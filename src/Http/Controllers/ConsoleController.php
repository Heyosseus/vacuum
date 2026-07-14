<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Http\Controllers;

use Heyosseus\Vacuum\Console\Console;
use Heyosseus\Vacuum\Database\ConnectionResolver;
use Heyosseus\Vacuum\Exceptions\RejectedStatement;
use Heyosseus\Vacuum\Values\Capabilities;
use Heyosseus\Vacuum\Values\ConsoleResult;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View as Views;
use Throwable;

final readonly class ConsoleController
{
    public function __construct(
        private Console $console,
        private Capabilities $capabilities,
        private ConnectionResolver $connections,
    ) {}

    public function show(): View
    {
        return $this->page();
    }

    public function run(Request $request): View
    {
        $request->validate([
            'statement' => ['required', 'string'],
        ]);

        $statement = $request->string('statement')->toString();

        try {
            return $this->page($statement, result: $this->console->run($statement));
        } catch (RejectedStatement $rejected) {
            return $this->page($statement, error: $rejected->getMessage());
        } catch (QueryException $failed) {
            // Whatever PostgreSQL said, said the way PostgreSQL said it. A console
            // that swallows the database's own words is a console that lies about
            // what happened.
            return $this->page($statement, error: $this->reason($failed));
        }
    }

    private function page(string $statement = '', ?ConsoleResult $result = null, ?string $error = null): View
    {
        return Views::make('vacuum::console', [
            'statement' => $statement,
            'result' => $result,
            'error' => $error,
            'capabilities' => $this->capabilities,
            'connection' => $this->connections->resolve()->getName() ?? 'unnamed',
        ]);
    }

    private function reason(Throwable $failed): string
    {
        $driver = $failed->getPrevious();

        return $driver instanceof Throwable ? $driver->getMessage() : $failed->getMessage();
    }
}
