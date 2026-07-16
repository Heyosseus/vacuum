<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Http\Controllers;

use Heyosseus\Vacuum\Console\Console;
use Heyosseus\Vacuum\Database\ConnectionResolver;
use Heyosseus\Vacuum\Exceptions\NestedTransaction;
use Heyosseus\Vacuum\Exceptions\RejectedStatement;
use Heyosseus\Vacuum\Exceptions\UnsupportedDriver;
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

    /**
     * The console, optionally with a statement already typed into it.
     *
     * A finding on the dashboard links here carrying the query that shows what the
     * rule saw. It is put in the box and left there: arriving at a page that has
     * already run something against your database, because you clicked a link, is
     * not a thing this package will ever do.
     */
    public function show(Request $request): View
    {
        return $this->page($request->string('statement')->toString());
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
        } catch (NestedTransaction|UnsupportedDriver $refused) {
            // Vacuum cannot offer its read-only guarantee here, so it declines
            // rather than runs. Both are the package's own sentences, written to be
            // read by a person; a 500 would say the same thing far worse.
            return $this->page($statement, error: $refused->getMessage());
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

            // Named rather than resolved: this page is the one that has to render
            // the failure when the connection cannot be resolved at all, so it must
            // not be the page that rethrows trying to name it.
            'connection' => $this->connections->name(),
        ]);
    }

    private function reason(Throwable $failed): string
    {
        $driver = $failed->getPrevious();

        return $driver instanceof Throwable ? $driver->getMessage() : $failed->getMessage();
    }
}
