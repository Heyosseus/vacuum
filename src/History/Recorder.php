<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\History;

use Carbon\CarbonImmutable;
use Heyosseus\Vacuum\Advisor\Advisor;
use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Health;
use Heyosseus\Vacuum\Database\ConnectionResolver;
use Heyosseus\Vacuum\History\Models\Snapshot;
use Heyosseus\Vacuum\Values\Capabilities;
use Illuminate\Contracts\Config\Repository;

/**
 * Writes one snapshot: the health the advisor found, its findings, and the raw
 * metrics behind them.
 *
 * This is the whole of Vacuum's write path, and it is careful about which database
 * it writes to. Everything is read first — the advisor and the collector each go to
 * the inspected database read-only, through the same rolled-back transactions
 * everything else uses — and only then is a single write transaction opened, on the
 * history connection, which is the application's own. The reads are finished before
 * the write begins, so the two never nest even if they are pointed at one database.
 */
final readonly class Recorder
{
    public function __construct(
        private Advisor $advisor,
        private SnapshotMetrics $metrics,
        private ConnectionResolver $resolver,
        private Capabilities $capabilities,
        private Repository $config,
    ) {}

    public function record(): Snapshot
    {
        $connection = $this->resolver->resolve();
        $findings = $this->advisor->findings();
        $health = Health::from($findings);
        $collected = $this->metrics->collect();

        $snapshot = new Snapshot([
            'connection' => $connection->getName() ?? 'default',
            'taken_at' => CarbonImmutable::now(),
            'created_at' => CarbonImmutable::now(),
            'server_version' => $this->capabilities->serverVersion,
            'health_score' => $health->score,
            'grade' => $health->grade->value,
        ]);

        $values = $this->findingValues($collected);

        $snapshot->getConnection()->transaction(function () use ($snapshot, $findings, $collected, $values): void {
            $snapshot->save();

            foreach ($findings as $finding) {
                $snapshot->findings()->create([
                    'rule' => $finding->rule,
                    'subject' => $finding->subject,
                    'severity' => $finding->severity->value,
                    'table_name' => $finding->table,
                    'summary' => $finding->summary,
                    'value' => $values[$this->findingKey($finding)] ?? null,
                ]);
            }

            foreach ($collected as $metric) {
                $snapshot->metrics()->create([
                    'kind' => $metric->kind,
                    'object' => $metric->object,
                    'value' => $metric->value,
                    'value2' => $metric->value2,
                ]);
            }
        });

        $this->prune();

        return $snapshot;
    }

    /**
     * The headline number for each finding that has one, indexed so a finding can
     * be given the value of its own metric without the two being collected twice.
     *
     * @param  list<CollectedMetric>  $collected
     * @return array<string, float|null>
     */
    private function findingValues(array $collected): array
    {
        $byKindAndObject = [];

        foreach ($collected as $metric) {
            $byKindAndObject[$metric->kind->value.'|'.$metric->object] = $metric->value;
        }

        return $byKindAndObject;
    }

    private function findingKey(Finding $finding): string
    {
        $kind = MetricKind::forRule($finding->rule);

        if (! $kind instanceof MetricKind || $finding->table === null) {
            // No trending number of its own, so nothing to look up; the writer
            // reads a missing key as a null value.
            return '';
        }

        return $kind->value.'|'.$finding->table;
    }

    private function prune(): void
    {
        $days = $this->config->get('vacuum.history.retention_days', 90);
        $days = is_numeric($days) ? (int) $days : 90;

        if ($days <= 0) {
            return;
        }

        $cutoff = CarbonImmutable::now()->subDays($days);

        // Deleting the parent rows takes their findings and metrics with them: the
        // model's delete cascades through the two relations so the sweep does not
        // depend on the database enforcing the foreign keys.
        Snapshot::query()
            ->where('taken_at', '<', $cutoff)
            ->each(static function (Snapshot $snapshot): void {
                $snapshot->findings()->delete();
                $snapshot->metrics()->delete();
                $snapshot->delete();
            });
    }
}
