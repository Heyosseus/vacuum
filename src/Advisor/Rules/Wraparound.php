<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor\Rules;

use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Advisor\TableRule;
use Heyosseus\Vacuum\Support\Identifier;
use Heyosseus\Vacuum\Values\TableStatistic;
use Illuminate\Contracts\Config\Repository;

/**
 * Finds tables whose oldest rows have not been frozen in a very long time.
 *
 * This is the one thing Vacuum reports that is not a performance opinion. Every
 * other rule describes a database that is slower than it could be; this one
 * describes a database that stops.
 *
 * PostgreSQL numbers transactions in 32 bits, so it can only tell the past from
 * the future for about two billion of them at a stretch. It buys itself room by
 * freezing old rows — marking them as visible to everyone, so their transaction
 * id no longer has to be compared against anything. Autovacuum does this on its
 * own, and on almost every database it does it forever without anyone noticing.
 *
 * When it cannot, the age climbs and nothing else changes: no error, no slowdown,
 * no symptom. Then the cluster reaches the end of its budget and refuses to
 * accept another write transaction until someone shuts the database down and
 * vacuums it in single-user mode. That is why this rule warns at an age nothing
 * is wrong at yet.
 */
final readonly class Wraparound implements TableRule
{
    public function __construct(private Repository $config) {}

    public function inspect(TableStatistic $table): ?Finding
    {
        if ($table->xidAge < $this->warningAge()) {
            return null;
        }

        $spent = number_format($table->transactionBudgetSpent() * 100, 1).'%';

        return new Finding(
            rule: 'wraparound',
            subject: $table->qualifiedName(),
            severity: $table->xidAge >= $this->criticalAge() ? Severity::Critical : Severity::Warning,
            summary: 'Nothing has frozen this table in '.number_format($table->xidAge).' transactions, '
                ."which is {$spent} of the ".number_format(TableStatistic::TRANSACTION_BUDGET)
                .' PostgreSQL can count before it must stop.',
            impact: 'Autovacuum should be freezing this table on its own, and the age should be falling back to '
                .'nearly nothing each time it does. An age that climbs past the freeze horizon and keeps climbing '
                .'means something is preventing that — most often a transaction left idle, an abandoned replication '
                .'slot, or a prepared transaction nobody committed, any of which holds the whole cluster back. '
                .'If the age reaches the limit, PostgreSQL refuses every new write transaction until the database '
                .'is shut down and vacuumed in single-user mode. It does not slow down first.',
            remediation: 'VACUUM (FREEZE, ANALYZE) '.Identifier::qualified($table->schema, $table->name).';',

            // The ten oldest tables in the cluster, not just this one. If something
            // is holding the horizon back, it is holding every table back, and the
            // shape of that list is what tells you which.
            query: "SELECT relname, age(relfrozenxid) AS xid_age\n"
                ."FROM pg_class\n"
                ."WHERE relkind = 'r'\n"
                ."ORDER BY age(relfrozenxid) DESC\n"
                .'LIMIT 10;',
            table: $table->qualifiedName(),
        );
    }

    /**
     * PostgreSQL's own autovacuum_freeze_max_age defaults to 200 million: the age
     * at which it stops waiting for a reason to vacuum the table and freezes it
     * regardless. Past that, autovacuum has been asked and has not delivered.
     */
    private function warningAge(): int
    {
        $age = $this->config->get('vacuum.thresholds.wraparound_xid_age', 200_000_000);

        return is_numeric($age) ? (int) $age : 200_000_000;
    }

    private function criticalAge(): int
    {
        $age = $this->config->get('vacuum.thresholds.wraparound_xid_age_critical', 1_000_000_000);

        return is_numeric($age) ? (int) $age : 1_000_000_000;
    }
}
