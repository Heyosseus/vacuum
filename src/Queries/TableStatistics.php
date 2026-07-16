<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Queries;

use Heyosseus\Vacuum\Database\ReadOnlyExecutor;
use Heyosseus\Vacuum\Support\Cast;
use Heyosseus\Vacuum\Support\IgnoredSchemas;
use Heyosseus\Vacuum\Support\SqlRepository;
use Heyosseus\Vacuum\Values\TableStatistic;

/**
 * Reads pg_stat_user_tables, the view PostgreSQL keeps of how each table has
 * been written to and when it was last vacuumed or analyzed, alongside the
 * freeze age pg_class keeps for it.
 */
final readonly class TableStatistics
{
    private const string STATEMENT = 'table_statistics';

    public function __construct(
        private ReadOnlyExecutor $executor,
        private SqlRepository $sql,
        private IgnoredSchemas $ignored,
    ) {}

    /**
     * Every table the configuration does not ignore, the most bloated first.
     *
     * @return list<TableStatistic>
     */
    public function all(): array
    {
        $ignored = implode(',', $this->ignored->all());

        return array_map(
            $this->toStatistic(...),
            $this->executor->select($this->sql->get(self::STATEMENT), [$ignored]),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function toStatistic(array $row): TableStatistic
    {
        return new TableStatistic(
            schema: Cast::text($row['schemaname'] ?? null),
            name: Cast::text($row['relname'] ?? null),
            liveTuples: Cast::integer($row['n_live_tup'] ?? null),
            deadTuples: Cast::integer($row['n_dead_tup'] ?? null),
            modificationsSinceAnalyze: Cast::integer($row['n_mod_since_analyze'] ?? null),
            xidAge: Cast::integer($row['xid_age'] ?? null),
            mxidAge: Cast::integer($row['mxid_age'] ?? null),
            lastVacuum: Cast::timestamp($row['last_vacuum'] ?? null),
            lastAutovacuum: Cast::timestamp($row['last_autovacuum'] ?? null),
            lastAnalyze: Cast::timestamp($row['last_analyze'] ?? null),
            lastAutoanalyze: Cast::timestamp($row['last_autoanalyze'] ?? null),
        );
    }
}
