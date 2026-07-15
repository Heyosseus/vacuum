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
 * Finds tables carrying more dead tuples than they should.
 *
 * A dead tuple is a row that has been deleted or updated but not yet reclaimed.
 * It still occupies a page, and PostgreSQL still reads that page. Enough of them
 * and every sequential scan of the table pays for rows nobody can see.
 */
final readonly class DeadTuples implements TableRule
{
    /** Half the table being dead is no longer a matter of tuning. */
    private const float CRITICAL_RATIO = 0.5;

    public function __construct(private Repository $config) {}

    public function inspect(TableStatistic $table): ?Finding
    {
        if ($table->deadTuples < $this->minimum()) {
            return null;
        }

        $ratio = $table->deadTupleRatio();

        if ($ratio < $this->threshold()) {
            return null;
        }

        $percentage = number_format($ratio * 100, 1).'%';

        return new Finding(
            rule: 'dead-tuples',
            subject: $table->qualifiedName(),
            severity: $ratio >= self::CRITICAL_RATIO ? Severity::Critical : Severity::Warning,
            summary: "{$percentage} of this table's tuples are dead: "
                .number_format($table->deadTuples).' dead against '
                .number_format($table->liveTuples).' live.',
            impact: 'Dead tuples still occupy pages, so every sequential scan reads rows no query can see, '
                .'and the space is not returned to the operating system until the table is vacuumed. '
                .'Autovacuum has either not kept up or is not running often enough for this table.',
            remediation: 'VACUUM ANALYZE '.Identifier::qualified($table->schema, $table->name).';',
            query: "SELECT n_live_tup, n_dead_tup, n_mod_since_analyze, last_vacuum, last_autovacuum\n"
                ."FROM pg_stat_user_tables\n"
                .'WHERE schemaname = '.Identifier::literal($table->schema)
                .' AND relname = '.Identifier::literal($table->name).';',
            table: $table->qualifiedName(),
        );
    }

    private function threshold(): float
    {
        $threshold = $this->config->get('vacuum.thresholds.dead_tuple_ratio', 0.2);

        return is_numeric($threshold) ? (float) $threshold : 0.2;
    }

    private function minimum(): int
    {
        $minimum = $this->config->get('vacuum.thresholds.dead_tuple_minimum', 1_000);

        return is_numeric($minimum) ? (int) $minimum : 1_000;
    }
}
