<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\History;

use Heyosseus\Vacuum\Advisor\Finding;
use Illuminate\Contracts\Config\Repository;

/**
 * Lays history over a list of findings for a page to render.
 *
 * With history off, or before it has run twice, every finding passes through
 * unchanged but for a trend of Unknown: the page looks exactly as it did before the
 * feature existed. With history on, each finding gains its direction and, where one
 * can honestly be drawn, its forecast — and the two findings built on a lifetime
 * average gain the figure measured over the last interval instead.
 */
final readonly class FindingPresenter
{
    public function __construct(
        private History $history,
        private Repository $config,
    ) {}

    /**
     * @param  list<Finding>  $findings
     * @return list<FindingView>
     */
    public function present(array $findings): array
    {
        if (! $this->history->enabled()) {
            return array_map(
                static fn (Finding $finding): FindingView => new FindingView($finding, Trend::Unknown, null, null),
                $findings,
            );
        }

        return array_map($this->view(...), $findings);
    }

    private function view(Finding $finding): FindingView
    {
        $kind = $finding->table === null ? null : MetricKind::forRule($finding->rule);

        $trend = ! $kind instanceof MetricKind || $finding->table === null
            ? Trend::Unknown
            : $this->history->direction($kind, $finding->table);

        $forecast = $kind instanceof MetricKind && $finding->table !== null && $kind->isMonotonic()
            ? $this->history->forecast($kind, $finding->table, $this->threshold($kind))
            : null;

        return new FindingView($finding, $trend, $forecast, $this->intervalSummary($finding));
    }

    /**
     * The line a forecast projects toward: the age wraparound turns critical at, and
     * the size at which bloat first becomes a finding. Both are absolute numbers a
     * byte series can actually be made to cross; the ratio thresholds are not.
     */
    private function threshold(MetricKind $kind): float
    {
        // Only the two monotonic kinds are ever forecast, so only they reach here.
        if ($kind === MetricKind::TableBloatBytes) {
            return $this->number('vacuum.thresholds.bloat_bytes', 100 * 1024 * 1024);
        }

        return $this->number('vacuum.thresholds.wraparound_xid_age_critical', 1_000_000_000);
    }

    private function intervalSummary(Finding $finding): ?string
    {
        return match ($finding->rule) {
            'cache-hit-ratio' => $this->cacheIntervalSummary(),
            'slow-statement' => $this->statementIntervalSummary($finding),
            default => null,
        };
    }

    private function cacheIntervalSummary(): ?string
    {
        $ratio = $this->history->intervalCacheHitRatio();

        if ($ratio === null) {
            return null;
        }

        $measured = number_format($ratio * 100, 1).'%';
        $target = number_format($this->number('vacuum.thresholds.cache_hit_ratio', 0.99) * 100, 1).'%';

        return "{$measured} of block reads were served from memory over the last interval, against a target of {$target}.";
    }

    private function statementIntervalSummary(Finding $finding): ?string
    {
        // The slow-statement finding's subject is "query {queryId}"; the id is what
        // the interval cost is keyed by.
        $queryId = trim(str_replace('query', '', $finding->subject));

        $cost = $this->history->intervalStatementCost($queryId);

        if ($cost === null) {
            return null;
        }

        $mean = number_format($cost['mean_ms']);
        $calls = number_format($cost['calls']);

        return "Averaged {$mean} ms across {$calls} calls over the last interval.";
    }

    private function number(string $key, float|int $default): float
    {
        $value = $this->config->get($key, $default);

        return is_numeric($value) ? (float) $value : (float) $default;
    }
}
