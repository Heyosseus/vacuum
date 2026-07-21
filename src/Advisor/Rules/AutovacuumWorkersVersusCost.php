<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor\Rules;

use Heyosseus\Vacuum\Advisor\ConfigurationRule;
use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Values\Settings;

/**
 * Finds more autovacuum workers than the vacuum cost budget was ever raised to
 * feed.
 *
 * autovacuum_vacuum_cost_limit is shared and balanced across every running
 * worker -- the sum of what the workers are each allowed to spend does not
 * exceed it. So raising autovacuum_max_workers on its own adds no vacuum
 * throughput at all; it divides the same budget into smaller slices and makes
 * each table's vacuum slower than it was with fewer workers.
 *
 * What is judged is the budget the workers actually get, which is not always the
 * number in autovacuum_vacuum_cost_limit: -1 there means inherit vacuum_cost_limit,
 * which defaults to 200. Firing on the sentinel rather than on the effective value
 * gets both directions wrong -- it accuses a server that correctly raised
 * vacuum_cost_limit, the one place that feeds both manual and automatic vacuum,
 * and it says nothing at all about ten workers splitting an explicit 200.
 *
 * The comparison is per-worker: PostgreSQL's own defaults give three workers a
 * budget of 200, so a slice thinner than that is the pathology, whatever the two
 * numbers making it up happen to be.
 */
final readonly class AutovacuumWorkersVersusCost implements ConfigurationRule
{
    private const int DEFAULT_WORKERS = 3;

    private const int DEFAULT_COST_LIMIT = 200;

    public function inspect(Settings $settings): ?Finding
    {
        $workers = $settings->integer('autovacuum_max_workers');

        if ($workers === null || $workers <= self::DEFAULT_WORKERS) {
            return null;
        }

        $effective = $this->effectiveCostLimit($settings);

        if ($effective === null || $effective <= 0) {
            return null;
        }

        // The share each worker gets, against the share each of the default
        // three would have got from the default budget.
        if ($effective >= self::DEFAULT_COST_LIMIT * $workers / self::DEFAULT_WORKERS) {
            return null;
        }

        $perWorker = (int) round($effective / $workers);

        return new Finding(
            rule: 'autovacuum-workers-vs-cost',
            subject: 'server',
            severity: Severity::Warning,
            summary: "autovacuum_max_workers is {$workers}, sharing a vacuum cost budget of {$effective} -- "
                ."about {$perWorker} each, where PostgreSQL's default three workers get "
                .(int) round(self::DEFAULT_COST_LIMIT / self::DEFAULT_WORKERS).'.',
            impact: 'autovacuum_vacuum_cost_limit is a shared budget: the cost every running worker is allowed '
                .'to spend before it must pause is balanced across all of them, so the sum never exceeds the '
                .'limit. Raising the worker count alone adds no throughput -- it slices the same budget more '
                .'ways and makes each table\'s vacuum take longer, not shorter.',
            // The budget that would give each of these workers what the default
            // three each get. Reaching this line at all means the current one is
            // below it, so there is no case where this suggests a reduction.
            remediation: 'Raise the cost limit along with the worker count, roughly in proportion: '
                .'ALTER SYSTEM SET autovacuum_vacuum_cost_limit = '
                .(int) round(self::DEFAULT_COST_LIMIT * $workers / self::DEFAULT_WORKERS)
                .";\nSELECT pg_reload_conf();",
            query: "SELECT name, setting, reset_val\n"
                ."FROM pg_settings\n"
                ."WHERE name IN ('autovacuum_max_workers', 'autovacuum_vacuum_cost_limit', 'vacuum_cost_limit');",
        );
    }

    /**
     * The budget the workers actually share.
     *
     * -1 is not a limit, it is a reference: PostgreSQL falls back to
     * vacuum_cost_limit, the setting that also governs a manual VACUUM. A server
     * that raised that one raised this one, and a rule that reads the sentinel
     * literally cannot see it.
     */
    private function effectiveCostLimit(Settings $settings): ?int
    {
        $limit = $settings->integer('autovacuum_vacuum_cost_limit');

        if ($limit === null) {
            return null;
        }

        return $limit === -1 ? $settings->integer('vacuum_cost_limit') : $limit;
    }
}
