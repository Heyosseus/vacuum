<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\History;

use Carbon\CarbonImmutable;
use Heyosseus\Vacuum\History\Models\Snapshot;
use Heyosseus\Vacuum\History\Models\SnapshotFinding;
use Heyosseus\Vacuum\History\Models\SnapshotMetric;
use Heyosseus\Vacuum\Support\Cast;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;

/**
 * Reads the stored snapshots to say what a single one cannot: which way a number is
 * moving, what it will be if it keeps moving that way, and what is wrong today that
 * was not wrong yesterday.
 *
 * It only ever reads. Snapshots are written by the Recorder; everything here is the
 * interpretation laid over them, kept out of the writer so that how history is read
 * can change without touching how it is recorded.
 */
final readonly class History
{
    /** A forecast is refused unless the line fits the points at least this well. */
    private const float FIT_FLOOR = 0.85;

    /**
     * Movement smaller than this share of the latest value is read as flat: a metric
     * that wobbles by a rounding error between snapshots is not trending.
     */
    private const float FLAT_BAND = 0.01;

    /**
     * A fall of more than this share of the previous value is read as a reset --
     * a freeze, a VACUUM FULL, a pg_repack -- rather than as movement, and the
     * series is cut there before anything is fitted through it.
     */
    private const float RESET_DROP = 0.10;

    public function __construct(private Repository $config) {}

    public function enabled(): bool
    {
        return (bool) $this->config->get('vacuum.history.enabled', false);
    }

    public function latest(): ?Snapshot
    {
        return Snapshot::query()->orderByDesc('taken_at')->first();
    }

    /**
     * The health score at each snapshot, oldest first, for the connection the most
     * recent one describes: the line the History page draws.
     *
     * @return list<array{at: CarbonImmutable, score: int}>
     */
    public function scores(): array
    {
        $connection = $this->latest()?->connection;

        if ($connection === null) {
            return [];
        }

        return array_values(
            Snapshot::query()
                ->where('connection', $connection)
                ->orderBy('taken_at')
                ->get()
                ->map(static fn (Snapshot $snapshot): array => [
                    'at' => $snapshot->taken_at,
                    'score' => $snapshot->health_score,
                ])
                ->all()
        );
    }

    /**
     * The cache-hit ratio over the last interval rather than over the life of the
     * server: the difference between the two db_cache snapshots, which is what the
     * database actually did between them. Null until two snapshots exist to subtract.
     *
     * The span the two snapshots actually cover comes back with it, because it is
     * the caller's job to name the period it is describing and only this method
     * knows what that period was.
     *
     * @return array{ratio: float, seconds: float}|null
     */
    public function intervalCacheHitRatio(): ?array
    {
        $rows = $this->recentRows(MetricKind::DbCache, 'database', 2);

        if (count($rows) < 2) {
            return null;
        }

        [$new, $old] = $rows;

        $hit = $new['value'] - $old['value'];
        $read = $new['value2'] - $old['value2'];
        $requested = $hit + $read;

        // A counter reset between snapshots turns a difference negative; there is no
        // honest ratio to report from it, so none is.
        if ($requested <= 0.0 || $hit < 0.0 || $read < 0.0) {
            return null;
        }

        return [
            'ratio' => $hit / $requested,
            'seconds' => max($new['at'] - $old['at'], 0.0),
        ];
    }

    /**
     * What one statement shape cost over the last interval: the time and the calls
     * added since the previous snapshot, and the mean of the two. Null until there
     * are two snapshots, or when the counters reset between them.
     *
     * The interval here is the one that genuinely elapsed between the two rows,
     * which for a statement is often not the snapshot cadence: only the fifty
     * costliest statements are stored per snapshot, so a query that dropped out of
     * that list and came back is being differenced across every snapshot it missed.
     *
     * @return array{total_ms: float, calls: float, mean_ms: float, seconds: float}|null
     */
    public function intervalStatementCost(string $queryId): ?array
    {
        $rows = $this->recentRows(MetricKind::Statement, $queryId, 2);

        if (count($rows) < 2) {
            return null;
        }

        [$new, $old] = $rows;

        $totalMs = $new['value'] - $old['value'];
        $calls = $new['value2'] - $old['value2'];

        if ($calls <= 0.0 || $totalMs < 0.0) {
            return null;
        }

        return [
            'total_ms' => $totalMs,
            'calls' => $calls,
            'mean_ms' => $totalMs / $calls,
            'seconds' => max($new['at'] - $old['at'], 0.0),
        ];
    }

    /**
     * Which way a metric has moved across recent snapshots. Uses the slope of the
     * line through them so a single noisy step does not read as a trend, and calls
     * anything inside a narrow band around flat exactly that.
     */
    public function direction(MetricKind $kind, string $object): Trend
    {
        $series = $this->series($kind, $object);

        if (count($series) < 2) {
            return Trend::Unknown;
        }

        $fit = LinearFit::through($series);

        if (! $fit instanceof LinearFit) {
            return Trend::Unknown;
        }

        [$firstX] = $series[0];
        [$lastX, $lastY] = $series[count($series) - 1];

        $change = $fit->slope * ($lastX - $firstX);
        $band = self::FLAT_BAND * max(abs($lastY), 1.0);

        return match (true) {
            $change > $band => Trend::Rising,
            $change < -$band => Trend::Falling,
            default => Trend::Flat,
        };
    }

    /**
     * When a forecastable metric is projected to cross a critical threshold, or null
     * when it should not be projected: the wrong kind of metric, too few points, a
     * line that does not fit them, a flat or falling trend, or a value already past
     * the line. Silence is the correct output for every one of those.
     *
     * Only the climb since the last reset is fitted. These metrics fall back to
     * nothing every time the maintenance they measure actually runs, and a line
     * drawn across one of those falls describes no future the database has.
     */
    public function forecast(MetricKind $kind, string $object, float $threshold): ?Forecast
    {
        if (! $kind->isForecastable()) {
            return null;
        }

        $series = $this->finalSegment($this->series($kind, $object));

        // Counted after the cut, not before: a long history whose last freeze was
        // yesterday has one point to reason from, however many it has in total.
        if (count($series) < $this->minimumSnapshots()) {
            return null;
        }

        $fit = LinearFit::through($series);

        if (! $fit instanceof LinearFit || $fit->slope <= 0.0 || $fit->rSquared < self::FIT_FLOOR) {
            return null;
        }

        [$lastX, $lastY] = $series[count($series) - 1];

        // Already at or over the line: that is a finding, not a forecast.
        if ($lastY >= $threshold) {
            return null;
        }

        $crossing = $fit->xFor($threshold);

        if ($crossing === null || $crossing <= $lastX) {
            return null;
        }

        $days = (int) ceil(($crossing - $lastX) / 86_400);

        return new Forecast(
            CarbonImmutable::createFromTimestamp((int) round($crossing)),
            max($days, 0),
            $fit->slope * 86_400,
        );
    }

    /**
     * The findings in the latest snapshot that were not in the one before it: what
     * is newly wrong. The substrate a later alerting layer sends; here it is only
     * read.
     *
     * @return list<SnapshotFinding>
     */
    public function newFindings(): array
    {
        [$latest, $previous] = $this->latestTwo();

        if ($latest === null) {
            return [];
        }

        if ($previous === null) {
            return array_values($latest->findings->all());
        }

        $before = $this->keyed($previous);

        return array_values($latest->findings
            ->reject(fn (SnapshotFinding $finding): bool => isset($before[$this->key($finding)]))
            ->all());
    }

    /**
     * The findings in the previous snapshot that are gone from the latest: what has
     * cleared since.
     *
     * @return list<SnapshotFinding>
     */
    public function clearedFindings(): array
    {
        [$latest, $previous] = $this->latestTwo();

        if ($latest === null || $previous === null) {
            return [];
        }

        $now = $this->keyed($latest);

        return array_values($previous->findings
            ->reject(fn (SnapshotFinding $finding): bool => isset($now[$this->key($finding)]))
            ->all());
    }

    /**
     * A metric's points across snapshots, oldest first, as [unix seconds, value],
     * scoped to the connection the most recent snapshot describes so two databases
     * sharing one store are not read as one.
     *
     * @return list<array{0: float, 1: float}>
     */
    public function series(MetricKind $kind, string $object): array
    {
        $connection = $this->latest()?->connection;

        if ($connection === null) {
            return [];
        }

        $points = [];

        // The query builder, not Eloquent, for a query that spans two tables: the
        // join and the table-qualified columns are what this needs, and a model
        // bound to one table is the wrong tool for reading across two.
        $rows = $this->connection()
            ->table('vacuum_snapshot_metrics as m')
            ->join('vacuum_snapshots as s', 's.id', '=', 'm.snapshot_id')
            ->where('m.kind', $kind->value)
            ->where('m.object', $object)
            ->where('s.connection', $connection)
            ->orderBy('s.taken_at')
            ->get(['m.value', 's.taken_at']);

        foreach ($rows as $row) {
            $points[] = [
                (float) (Cast::timestamp($row->taken_at)?->getTimestamp() ?? 0),
                Cast::decimal($row->value),
            ];
        }

        return $points;
    }

    /**
     * The most recent rows for a metric, newest first, with both values intact for
     * the paired counters that need them and the time each was taken.
     *
     * taken_at comes back because the two most recent rows for a metric are not
     * necessarily one interval apart. A snapshot stores only the fifty costliest
     * statements and that ranking reshuffles constantly, so a query that fell out
     * of the top fifty for four hours has its next appearance differenced against
     * the one before it. The subtraction is still valid -- it is a difference of
     * two counters -- but the span it covers is whatever it is, and the caller
     * has to be told which so it can say so.
     *
     * @return list<array{value: float, value2: float, at: float}>
     */
    private function recentRows(MetricKind $kind, string $object, int $limit): array
    {
        $connection = $this->latest()?->connection;

        if ($connection === null) {
            return [];
        }

        $rows = $this->connection()
            ->table('vacuum_snapshot_metrics as m')
            ->join('vacuum_snapshots as s', 's.id', '=', 'm.snapshot_id')
            ->where('m.kind', $kind->value)
            ->where('m.object', $object)
            ->where('s.connection', $connection)
            ->orderByDesc('s.taken_at')
            ->limit($limit)
            ->get(['m.value', 'm.value2', 's.taken_at']);

        $values = [];

        foreach ($rows as $row) {
            $values[] = [
                'value' => Cast::decimal($row->value),
                'value2' => Cast::decimal($row->value2),
                'at' => (float) (Cast::timestamp($row->taken_at)?->getTimestamp() ?? 0),
            ];
        }

        return $values;
    }

    /**
     * The run of points since the metric last reset.
     *
     * A freeze takes age(relfrozenxid) back to nearly zero and a VACUUM FULL takes
     * bloat with it, so the "monotonic" metrics are sawtooths and a single line
     * through a whole retention window is a line through several unrelated climbs.
     * The damage is not that the fit is poor -- a poor fit is refused by the r²
     * floor and no harm done -- it is that a series with one early reset and a
     * clean climb after it fits *well*, clears the floor, and reports a crossing
     * date further away than the truth. Optimism is the one direction a wraparound
     * forecast must not be wrong in.
     *
     * @param  list<array{0: float, 1: float}>  $series
     * @return list<array{0: float, 1: float}>
     */
    private function finalSegment(array $series): array
    {
        $start = 0;
        $counter = count($series);

        for ($i = 1; $i < $counter; $i++) {
            [, $previous] = $series[$i - 1];
            [, $current] = $series[$i];

            // A drop of any real size on a metric that only climbs is not noise,
            // it is the maintenance that reset it.
            if ($current < $previous * (1.0 - self::RESET_DROP)) {
                $start = $i;
            }
        }

        return array_slice($series, $start);
    }

    /**
     * The database connection history is stored on: the model's own, so it honours
     * vacuum.history.connection without the resolution being written twice.
     */
    private function connection(): ConnectionInterface
    {
        return (new SnapshotMetric)->getConnection();
    }

    /**
     * @return array{0: ?Snapshot, 1: ?Snapshot} Latest and the one before it, both
     *                                           on the latest snapshot's connection.
     */
    private function latestTwo(): array
    {
        $latest = $this->latest();

        if (! $latest instanceof Snapshot) {
            return [null, null];
        }

        $previous = Snapshot::query()
            ->where('connection', $latest->connection)
            ->where('taken_at', '<', $latest->taken_at)
            ->orderByDesc('taken_at')
            ->first();

        $latest->load('findings');
        $previous?->load('findings');

        return [$latest, $previous];
    }

    /**
     * @return array<string, true>
     */
    private function keyed(Snapshot $snapshot): array
    {
        $keys = [];

        foreach ($snapshot->findings as $finding) {
            $keys[$this->key($finding)] = true;
        }

        return $keys;
    }

    private function key(SnapshotFinding $finding): string
    {
        return $finding->rule.'|'.$finding->subject;
    }

    private function minimumSnapshots(): int
    {
        $minimum = $this->config->get('vacuum.history.forecast.minimum_snapshots', 12);

        return is_numeric($minimum) ? max(2, (int) $minimum) : 12;
    }
}
