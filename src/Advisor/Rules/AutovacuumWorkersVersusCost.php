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
 * each table's vacuum slower than it was with fewer workers. -1 means the limit
 * inherits vacuum_cost_limit, which itself defaults to 200.
 */
final readonly class AutovacuumWorkersVersusCost implements ConfigurationRule
{
    public function inspect(Settings $settings): ?Finding
    {
        $workers = $settings->integer('autovacuum_max_workers');

        if ($workers === null || $workers <= 3) {
            return null;
        }

        if ($settings->value('autovacuum_vacuum_cost_limit') !== '-1') {
            return null;
        }

        return new Finding(
            rule: 'autovacuum-workers-vs-cost',
            subject: 'server',
            severity: Severity::Warning,
            summary: "autovacuum_max_workers is {$workers}, but autovacuum_vacuum_cost_limit was never raised "
                .'to match.',
            impact: 'autovacuum_vacuum_cost_limit is a shared budget: the cost every running worker is allowed '
                .'to spend before it must pause is balanced across all of them, so the sum never exceeds the '
                .'limit. Raising the worker count alone adds no throughput -- it slices the same budget more '
                .'ways and makes each table\'s vacuum take longer, not shorter. -1 here means the limit is '
                .'inherited from vacuum_cost_limit, which defaults to 200.',
            remediation: 'Raise the cost limit along with the worker count, roughly in proportion: '
                ."ALTER SYSTEM SET autovacuum_vacuum_cost_limit = 2000;\nSELECT pg_reload_conf();",
            query: "SELECT name, setting\n"
                ."FROM pg_settings\n"
                ."WHERE name IN ('autovacuum_max_workers', 'autovacuum_vacuum_cost_limit', 'vacuum_cost_limit');",
        );
    }
}
