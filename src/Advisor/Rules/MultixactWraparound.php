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
 * Finds tables whose oldest row locks have not been frozen in a very long time.
 *
 * PostgreSQL has two wraparound clocks, not one, and the cluster stops when
 * either of them runs out. The Wraparound rule watches the first: transaction
 * ids, spent by writing. This rule watches the second.
 *
 * When a single transaction locks a row, PostgreSQL records its transaction id
 * on the row and that is the end of it. When more than one transaction holds a
 * lock on the same row at the same time — two transactions holding SELECT ...
 * FOR SHARE, or a foreign key check taking a lock a writer already holds — one
 * id is no longer enough to describe who holds it, so PostgreSQL allocates a
 * *multixact*: a separate object, from a separate 32-bit counter, that names the
 * whole set. That counter has its own horizon and its own freeze age, and
 * autovacuum advances it on its own schedule.
 *
 * The consequence is that this age is driven by locking rather than by writing.
 * A table under an FK-heavy or SELECT ... FOR UPDATE workload can be frozen on
 * schedule by every measure the transaction clock knows about, and still be the
 * table that stops the cluster. Watching only relfrozenxid is watching one of
 * two doors.
 *
 * The remedy is identical — VACUUM (FREEZE, ANALYZE) advances both horizons —
 * which is exactly why the detection has to be separate: the fix was never the
 * missing half, the seeing was.
 */
final readonly class MultixactWraparound implements TableRule
{
    public function __construct(private Repository $config) {}

    public function inspect(TableStatistic $table): ?Finding
    {
        if ($table->mxidAge < $this->warningAge()) {
            return null;
        }

        $spent = number_format($table->multixactBudgetSpent() * 100, 1).'%';

        return new Finding(
            rule: 'multixact-wraparound',
            subject: $table->qualifiedName(),
            severity: $table->mxidAge >= $this->criticalAge() ? Severity::Critical : Severity::Warning,
            summary: 'Nothing has frozen this table\'s row locks in '.number_format($table->mxidAge)
                .' multixacts, which is '."{$spent} of the ".number_format(TableStatistic::MULTIXACT_BUDGET)
                .' PostgreSQL can count before it must stop.',
            impact: 'PostgreSQL allocates a multixact whenever more than one transaction holds a lock on the same '
                .'row at once, which is ordinary on a table with foreign keys pointing at it or one that is read '
                .'with SELECT ... FOR UPDATE or FOR SHARE. Multixacts are numbered by their own 32-bit counter, '
                .'with their own freeze horizon, and autovacuum advances it separately from the transaction one. '
                .'That is why this age can climb on a table whose freeze age looks perfectly healthy. The ending is '
                .'the same: if the age reaches the limit, PostgreSQL refuses every new write transaction until the '
                .'database is shut down and vacuumed in single-user mode. It does not slow down first.',
            remediation: 'VACUUM (FREEZE, ANALYZE) '.Identifier::qualified($table->schema, $table->name).';',

            // The ten oldest tables in the cluster by multixact age. As with the
            // transaction clock, whatever is holding the horizon back is holding
            // every table back, and the shape of the list is what says which.
            query: "SELECT relname, mxid_age(relminmxid) AS mxid_age\n"
                ."FROM pg_class\n"
                ."WHERE relkind = 'r'\n"
                ."ORDER BY mxid_age(relminmxid) DESC\n"
                .'LIMIT 10;',
            table: $table->qualifiedName(),
        );
    }

    /**
     * PostgreSQL's own autovacuum_multixact_freeze_max_age defaults to 400 million
     * — twice the transaction clock's horizon, and the reason this rule does not
     * simply reuse that threshold. Past it, autovacuum has been asked to freeze the
     * table regardless of anything else and has not delivered.
     */
    private function warningAge(): int
    {
        $age = $this->config->get('vacuum.thresholds.wraparound_mxid_age', 400_000_000);

        return is_numeric($age) ? (int) $age : 400_000_000;
    }

    private function criticalAge(): int
    {
        $age = $this->config->get('vacuum.thresholds.wraparound_mxid_age_critical', 1_000_000_000);

        return is_numeric($age) ? (int) $age : 1_000_000_000;
    }
}
