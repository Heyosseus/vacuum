<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Queries;

use Heyosseus\Vacuum\Database\ReadOnlyExecutor;
use Heyosseus\Vacuum\Support\Cast;
use Heyosseus\Vacuum\Values\Statement;

/**
 * Reads what pg_stat_statements has been keeping about the queries this
 * application runs.
 */
final readonly class Statements
{
    /** Enough to find the problem, few enough not to become one. */
    private const int LIMIT = 50;

    public function __construct(private ReadOnlyExecutor $executor) {}

    /**
     * The slowest shapes of query, by what each one costs on average.
     *
     * pg_stat_statements keeps one row per (userid, dbid, queryid, toplevel) —
     * not one row per query shape. The same normalized statement run by the
     * application's role and by a migration's role is two rows carrying the same
     * queryid, and reading them unaggregated showed the query twice, gave the
     * Filament model a primary key that was not unique, and let two rows collide
     * in the history snapshot's per-queryid metrics.
     *
     * So the rows are summed into the thing everybody means by "a query": the
     * shape, whoever ran it. The mean is recomputed from the summed total over
     * the summed calls rather than averaged from the rows' own means, which
     * would weight a role that ran the query twice the same as one that ran it a
     * million times.
     *
     * Rows are not filtered to toplevel. With pg_stat_statements.track at its
     * default of 'top' there is nothing but toplevel rows to filter; and on a
     * server deliberately set to 'all', the statements running inside functions
     * are exactly what that operator asked to be shown, so hiding them here
     * would answer a question nobody asked.
     *
     * @return list<Statement>
     */
    public function slowest(): array
    {
        // The NOT LIKE keeps Vacuum's own reading of the statistics out of the
        // statistics. A dashboard reporting the cost of watching the dashboard is
        // a dashboard nobody trusts twice.
        //
        // A null queryid is dropped rather than grouped: the server could not
        // identify those statements, so they are not one shape, and collapsing
        // them together would invent a query that nobody ran.
        $sql = <<<'SQL'
            SELECT
                queryid,
                min(query) AS query,
                sum(calls) AS calls,
                sum(total_exec_time) AS total_exec_time,
                sum(total_exec_time) / nullif(sum(calls), 0) AS mean_exec_time,
                sum(rows) AS rows
            FROM pg_stat_statements
            WHERE dbid = (SELECT oid FROM pg_database WHERE datname = current_database())
                AND queryid IS NOT NULL
                AND query NOT LIKE '%pg_stat_%'
            GROUP BY queryid
            ORDER BY mean_exec_time DESC NULLS LAST
            LIMIT ?
            SQL;

        return array_map($this->toStatement(...), $this->executor->select($sql, [self::LIMIT]));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function toStatement(array $row): Statement
    {
        return new Statement(
            queryId: Cast::text($row['queryid'] ?? null),
            sql: Cast::text($row['query'] ?? null),
            calls: Cast::integer($row['calls'] ?? null),
            totalMilliseconds: Cast::decimal($row['total_exec_time'] ?? null),
            meanMilliseconds: Cast::decimal($row['mean_exec_time'] ?? null),
            rows: Cast::integer($row['rows'] ?? null),
        );
    }
}
