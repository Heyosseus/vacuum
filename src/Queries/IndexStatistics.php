<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Queries;

use Heyosseus\Vacuum\Database\ReadOnlyExecutor;
use Heyosseus\Vacuum\Support\Cast;
use Heyosseus\Vacuum\Support\IgnoredSchemas;
use Heyosseus\Vacuum\Values\IndexStatistic;

/**
 * Reads how often each index has been used, and what it costs to keep.
 *
 * The scan counts come with the reset timestamp deliberately: "no query has used
 * this index" means nothing without "since when", and PostgreSQL's counters start
 * again from zero whenever somebody calls pg_stat_reset.
 */
final readonly class IndexStatistics
{
    public function __construct(
        private ReadOnlyExecutor $executor,
        private IgnoredSchemas $ignored,
    ) {}

    /**
     * @return list<IndexStatistic>
     */
    public function all(): array
    {
        $sql = <<<'SQL'
            SELECT
                statistics.schemaname,
                statistics.relname,
                statistics.indexrelname,
                statistics.idx_scan,
                pg_relation_size(statistics.indexrelid) AS index_bytes,
                indexes.indisunique,
                indexes.indisprimary,
                indexes.indisvalid,
                indexes.indisreplident,
                relations.relispartition,
                EXISTS (
                    SELECT 1
                    FROM pg_depend AS dependencies
                    WHERE dependencies.classid = 'pg_class'::regclass
                      AND dependencies.objid = statistics.indexrelid
                      AND dependencies.refclassid = 'pg_constraint'::regclass
                      AND dependencies.deptype = 'i'
                ) AS constraint_owned,
                (SELECT stats_reset FROM pg_stat_database WHERE datname = current_database()) AS counting_since
            FROM pg_stat_user_indexes AS statistics
            JOIN pg_index AS indexes ON indexes.indexrelid = statistics.indexrelid
            JOIN pg_class AS relations ON relations.oid = statistics.indexrelid
            WHERE statistics.schemaname <> ALL (string_to_array(?, ','))
            ORDER BY statistics.idx_scan, pg_relation_size(statistics.indexrelid) DESC
            SQL;

        return array_map(
            $this->toStatistic(...),
            $this->executor->select($sql, [implode(',', $this->ignored->all())]),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function toStatistic(array $row): IndexStatistic
    {
        return new IndexStatistic(
            schema: Cast::text($row['schemaname'] ?? null),
            table: Cast::text($row['relname'] ?? null),
            name: Cast::text($row['indexrelname'] ?? null),
            scans: Cast::integer($row['idx_scan'] ?? null),
            bytes: Cast::integer($row['index_bytes'] ?? null),
            unique: Cast::boolean($row['indisunique'] ?? null),
            primary: Cast::boolean($row['indisprimary'] ?? null),
            valid: Cast::boolean($row['indisvalid'] ?? null),
            constraintOwned: Cast::boolean($row['constraint_owned'] ?? null),
            replicaIdentity: Cast::boolean($row['indisreplident'] ?? null),
            partitionChild: Cast::boolean($row['relispartition'] ?? null),
            countingSince: Cast::timestamp($row['counting_since'] ?? null),
        );
    }
}
