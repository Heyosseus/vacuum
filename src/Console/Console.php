<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Console;

use Heyosseus\Vacuum\Database\ReadOnlyExecutor;
use Heyosseus\Vacuum\Exceptions\RejectedStatement;
use Heyosseus\Vacuum\Values\ConsoleResult;
use Illuminate\Contracts\Config\Repository;

/**
 * Runs one statement, reads what came back, and keeps neither.
 */
final readonly class Console
{
    public function __construct(
        private StatementGuard $guard,
        private ReadOnlyExecutor $executor,
        private Repository $config,
    ) {}

    /**
     * @throws RejectedStatement
     */
    public function run(string $statement): ConsoleResult
    {
        $this->guard->check($statement);

        $started = microtime(true);

        // The executor is the boundary: it opens a READ ONLY transaction, sets a
        // statement timeout, and rolls back whatever happens. Nothing this method
        // does can write, however the statement was spelled.
        $rows = $this->executor->select($statement, [], $this->timeout());

        $milliseconds = (microtime(true) - $started) * 1_000;

        $shown = array_slice($rows, 0, $this->maxRows());

        return new ConsoleResult(
            columns: array_keys($shown[0] ?? []),
            rows: $shown,
            found: count($rows),
            milliseconds: $milliseconds,
        );
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
