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
        $this->guard->check($statement);

        $maxRows = $this->maxRows();
        $started = microtime(true);

        try {
            // The executor is the boundary: it opens a READ ONLY transaction, sets a
            // statement timeout, and rolls back whatever happens. Nothing this method
            // does can write, however the statement was spelled.
            $rows = $this->executor->select($this->capped($statement, $maxRows), [], $this->timeout());
        } catch (Throwable $failed) {
            // A statement the server refused is still a statement somebody ran, and
            // it is often the one worth having a record of.
            $this->record($statement, null, $started);

            throw $failed;
        }

        // One row over the cap was asked for, so more than the cap coming back is
        // how the console knows the answer was cut without ever counting the rest.
        $capped = count($rows) > $maxRows;
        $shown = array_slice($rows, 0, $maxRows);

        $this->record($statement, count($shown), $started);

        return new ConsoleResult(
            columns: array_keys($shown[0] ?? []),
            rows: $shown,
            capped: $capped,
            milliseconds: $this->elapsed($started),
        );
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
     * This bounds the number of rows. It does not bound the width of one: a single
     * enormous value — SELECT repeat('x', 500000000) — is still one row, and still
     * arrives whole. That case is left to statement_timeout and PHP's memory_limit,
     * and is documented rather than pretended away.
     */
    private function capped(string $statement, int $maxRows): string
    {
        $sql = trim(rtrim(trim($statement), ';'));

        if (! $this->cappable($sql)) {
            return $sql;
        }

        // maxRows is an int from configuration, so it is constrained by its type
        // rather than by escaping; the user's statement is not interpolated into
        // anything, only wrapped.
        $limit = $maxRows + 1;

        return "SELECT * FROM (\n{$sql}\n) AS vacuum_console LIMIT {$limit}";
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
}
