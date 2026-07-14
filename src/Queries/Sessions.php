<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Queries;

use Heyosseus\Vacuum\Database\ReadOnlyExecutor;
use Heyosseus\Vacuum\Support\Cast;
use Heyosseus\Vacuum\Values\Session;

/**
 * Reads what every connection to this database is currently doing.
 *
 * The ages are worked out by PostgreSQL rather than by PHP. The application server
 * and the database server do not necessarily agree about the time, and a
 * transaction's age is not the sort of number to be casual about.
 */
final readonly class Sessions
{
    public function __construct(private ReadOnlyExecutor $executor) {}

    /**
     * @return list<Session>
     */
    public function all(): array
    {
        $sql = <<<'SQL'
            SELECT
                pid,
                coalesce(usename, '') AS usename,
                coalesce(application_name, '') AS application_name,
                coalesce(state, '') AS state,
                coalesce(query, '') AS query,
                coalesce(extract(epoch FROM (now() - xact_start)), 0)::int AS transaction_seconds,
                coalesce(extract(epoch FROM (now() - state_change)), 0)::int AS state_seconds,
                array_to_string(pg_blocking_pids(pid), ',') AS blocked_by
            FROM pg_stat_activity
            WHERE datname = current_database()
                AND backend_type = 'client backend'
            ORDER BY transaction_seconds DESC, pid
            SQL;

        return array_map($this->toSession(...), $this->executor->select($sql));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function toSession(array $row): Session
    {
        return new Session(
            pid: Cast::integer($row['pid'] ?? null),
            user: Cast::text($row['usename'] ?? null),
            application: Cast::text($row['application_name'] ?? null),
            state: Cast::text($row['state'] ?? null),
            query: Cast::text($row['query'] ?? null),
            transactionSeconds: Cast::integer($row['transaction_seconds'] ?? null),
            stateSeconds: Cast::integer($row['state_seconds'] ?? null),
            blockedBy: Cast::integers($row['blocked_by'] ?? null),
        );
    }
}
