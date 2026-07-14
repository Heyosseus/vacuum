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
     * @return list<Statement>
     */
    public function slowest(): array
    {
        // The NOT LIKE keeps Vacuum's own reading of the statistics out of the
        // statistics. A dashboard reporting the cost of watching the dashboard is
        // a dashboard nobody trusts twice.
        $sql = <<<'SQL'
            SELECT
                queryid,
                query,
                calls,
                total_exec_time,
                mean_exec_time,
                rows
            FROM pg_stat_statements
            WHERE dbid = (SELECT oid FROM pg_database WHERE datname = current_database())
                AND query NOT LIKE '%pg_stat_%'
            ORDER BY mean_exec_time DESC
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
