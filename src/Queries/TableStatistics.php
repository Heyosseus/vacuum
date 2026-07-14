<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Queries;

use Heyosseus\Vacuum\Database\ReadOnlyExecutor;
use Heyosseus\Vacuum\Support\Cast;
use Heyosseus\Vacuum\Support\IgnoredSchemas;
use Heyosseus\Vacuum\Values\TableStatistic;

/**
 * Reads pg_stat_user_tables, the view PostgreSQL keeps of how each table has
 * been written to and when it was last vacuumed or analyzed.
 */
final readonly class TableStatistics
{
    public function __construct(
        private ReadOnlyExecutor $executor,
        private IgnoredSchemas $ignored,
    ) {}

    /**
     * Every table the configuration does not ignore, the most bloated first.
     *
     * @return list<TableStatistic>
     */
    public function all(): array
    {
        $ignored = $this->ignored->all();

        $sql = <<<'SQL'
            SELECT
                schemaname,
                relname,
                n_live_tup,
                n_dead_tup,
                n_mod_since_analyze,
                last_vacuum,
                last_autovacuum,
                last_analyze,
                last_autoanalyze
            FROM pg_stat_user_tables
            SQL;

        if ($ignored !== []) {
            $placeholders = implode(', ', array_fill(0, count($ignored), '?'));
            $sql .= "\nWHERE schemaname NOT IN ({$placeholders})";
        }

        $sql .= "\nORDER BY n_dead_tup DESC, relname";

        return array_map(
            $this->toStatistic(...),
            $this->executor->select($sql, $ignored),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function toStatistic(array $row): TableStatistic
    {
        return new TableStatistic(
            schema: Cast::text($row['schemaname']),
            name: Cast::text($row['relname']),
            liveTuples: Cast::integer($row['n_live_tup']),
            deadTuples: Cast::integer($row['n_dead_tup']),
            modificationsSinceAnalyze: Cast::integer($row['n_mod_since_analyze']),
            lastVacuum: Cast::timestamp($row['last_vacuum']),
            lastAutovacuum: Cast::timestamp($row['last_autovacuum']),
            lastAnalyze: Cast::timestamp($row['last_analyze']),
            lastAutoanalyze: Cast::timestamp($row['last_autoanalyze']),
        );
    }
}
