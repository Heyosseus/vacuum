<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Console;

use Heyosseus\Vacuum\Database\ConnectionResolver;
use Heyosseus\Vacuum\Database\ReadOnlyExecutor;
use Heyosseus\Vacuum\Exceptions\RejectedStatement;
use Heyosseus\Vacuum\Values\ConsoleResult;
use Illuminate\Contracts\Config\Repository;
use Throwable;

/**
 * Runs one statement, reads what came back, and keeps neither.
 */
final readonly class Console
{
    /**
     * The statements PostgreSQL will accept as a subquery, which is what the row cap
     * is built out of. EXPLAIN and SHOW are not among them — they are not queries but
     * commands about queries, and wrapping them is a syntax error. They are also
     * bounded by their own nature: a plan and a setting. So they run unwrapped, and
     * nothing is lost by it.
     *
     * @var list<string>
     */
    private const array CAPPABLE = ['SELECT', 'WITH', 'TABLE', 'VALUES'];

    /**
     * The bookkeeping columns the cap wraps around a result. They are read to
     * decide whether the answer was cut and then removed, so what the page renders
     * is the statement's own columns and nothing this class added.
     *
     * @var list<string>
     */
    private const array BOOKKEEPING = [
        'vacuum_console_row_bytes',
        'vacuum_console_bytes',
        'vacuum_console_rows',
    ];

    public function __construct(
        private StatementGuard $guard,
        private ReadOnlyExecutor $executor,
        private ConsoleAudit $audit,
        private ConnectionResolver $connections,
        private Repository $config,
    ) {}

    /**
     * @throws RejectedStatement
     */
    public function run(string $statement): ConsoleResult
    {
        // What the guard approved is what gets run. It returns the normalized
        // statement for exactly that reason: checking one string and executing
        // another is a hole no amount of checking closes.
        $checked = $this->guard->check($statement);

        $maxRows = $this->maxRows();
        $started = microtime(true);

        try {
            // The executor is the boundary: it opens a READ ONLY transaction, sets a
            // statement timeout, and rolls back whatever happens. Nothing this method
            // does can write, however the statement was spelled.
            $rows = $this->executor->select($this->capped($checked, $maxRows), [], $this->timeout());
        } catch (Throwable $failed) {
            // A statement the server refused is still a statement somebody ran, and
            // it is often the one worth having a record of.
            $this->record($statement, null, $started);

            throw $failed;
        }

        // The wrapper asked for one row over the cap and reported how many it
        // produced, so both ways of being cut are visible without counting
        // anything the server did not send: more rows than the cap, or fewer rows
        // arriving than the wrapper says it had.
        $produced = $this->produced($rows);
        $capped = $produced > $maxRows || count($rows) < $produced;
        $shown = array_map($this->withoutBookkeeping(...), array_slice($rows, 0, $maxRows));

        $this->record($statement, count($shown), $started);

        return new ConsoleResult(
            columns: array_keys($shown[0] ?? []),
            rows: $shown,
            capped: $capped,
            milliseconds: $this->elapsed($started),
        );
    }

    /**
     * How many rows the wrapper had before the byte budget was applied.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function produced(array $rows): int
    {
        $count = $rows[0]['vacuum_console_rows'] ?? null;

        return is_numeric($count) ? (int) $count : count($rows);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function withoutBookkeeping(array $row): array
    {
        foreach (self::BOOKKEEPING as $column) {
            unset($row[$column]);
        }

        return $row;
    }

    /**
     * The statement, bounded by the server rather than by PHP.
     *
     * max_rows used to be applied to a result that was already whole and already in
     * memory: statement_timeout limits how long a statement runs, not how much it
     * hands back, so a generate_series that finishes instantly could still take the
     * worker down. Asking PostgreSQL for max_rows + 1 means the rows beyond the cap
     * are never produced, never sent, and never allocated.
     *
     * Rows were only half of it. A row cap bounds how many results come back and
     * says nothing about how wide each one is: 300 rows of a megabyte each is well
     * inside a cap of 500 and is 300 MB into a web worker, produced in under a
     * second, which no statement timeout will stop because the timeout bounds
     * execution rather than transfer. So a byte budget rides alongside, as a
     * running total the server keeps and filters on — the same shape as the row
     * cap, and the same reason it works: the rows past the budget are never sent.
     *
     * The row that crosses the budget is kept rather than dropped, so a result is
     * never silently empty and the caller can always tell a cut answer from no
     * answer. The consequence is that a single enormous value — SELECT repeat('x',
     * 500000000) — still arrives whole, because it is the first row and there is
     * nothing before it to have exceeded anything. That case remains what it
     * always was: bounded by statement_timeout and PHP's memory_limit, and
     * documented rather than pretended away.
     */
    private function capped(string $statement, int $maxRows): string
    {
        $sql = trim(rtrim(trim($statement), ';'));

        if (! $this->cappable($sql)) {
            return $sql;
        }

        // Both bounds are ints from configuration, so they are constrained by
        // their type rather than by escaping; the user's statement is not
        // interpolated into anything, only wrapped.
        $limit = $maxRows + 1;
        $maxBytes = $this->maxBytes();

        // The row limit is applied in its own subquery because a window function
        // is evaluated before LIMIT: computed one level up, the running total
        // would be taken over every row the statement produced, which is the cost
        // the cap exists to avoid paying.
        return <<<SQL
            SELECT * FROM (
                SELECT
                    capped.*,
                    octet_length(capped::text) AS vacuum_console_row_bytes,
                    sum(octet_length(capped::text)) OVER (ROWS UNBOUNDED PRECEDING) AS vacuum_console_bytes,
                    count(*) OVER () AS vacuum_console_rows
                FROM (SELECT * FROM (
            {$sql}
                ) AS vacuum_console LIMIT {$limit}) AS capped
            ) AS vacuum_console_budget
            WHERE vacuum_console_bytes - vacuum_console_row_bytes < {$maxBytes}
            SQL;
    }

    private function cappable(string $sql): bool
    {
        $opener = strtoupper((string) preg_replace('/\W.*$/s', '', $sql));

        return in_array($opener, self::CAPPABLE, true);
    }

    /**
     * The connection is named rather than resolved, because this also runs on the
     * path where resolving is what just failed: an audit line about an attempt
     * against an unusable connection is exactly the line worth keeping, and it
     * cannot be the thing that throws instead.
     */
    private function record(string $statement, ?int $rows, float $started): void
    {
        $this->audit->record($statement, $rows, $this->elapsed($started), $this->connections->name());
    }

    private function elapsed(float $started): float
    {
        return (microtime(true) - $started) * 1_000;
    }

    private function timeout(): int
    {
        $timeout = $this->config->get('vacuum.console.timeout', 5_000);

        return is_numeric($timeout) ? (int) $timeout : 5_000;
    }

    private function maxRows(): int
    {
        $rows = $this->config->get('vacuum.console.max_rows', 500);

        return is_numeric($rows) ? (int) $rows : 500;
    }

    private function maxBytes(): int
    {
        $bytes = $this->config->get('vacuum.console.max_bytes', 8 * 1024 * 1024);

        return is_numeric($bytes) ? (int) $bytes : 8 * 1024 * 1024;
    }
}
