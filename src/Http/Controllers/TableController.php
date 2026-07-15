<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Http\Controllers;

use Heyosseus\Vacuum\Advisor\Advisor;
use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Database\ConnectionResolver;
use Heyosseus\Vacuum\Queries\IndexStatistics;
use Heyosseus\Vacuum\Queries\TableProfiles;
use Heyosseus\Vacuum\Values\Capabilities;
use Heyosseus\Vacuum\Values\IndexStatistic;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as Views;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One table, and everything the package knows about it.
 *
 * The dashboard says a table is in trouble. This says what the table is: what it
 * costs, how it is read, how it is written, when anything last cleaned it up, and
 * how far it is from the point where autovacuum will do so again. It is where you
 * go after the finding, and it is the reason a finding names its table.
 */
final readonly class TableController
{
    public function __construct(
        private TableProfiles $tables,
        private IndexStatistics $indexes,
        private Advisor $advisor,
        private Capabilities $capabilities,
        private ConnectionResolver $connections,
    ) {}

    public function __invoke(string $schema, string $table): View
    {
        $profile = $this->tables->find($schema, $table);

        if (! $profile instanceof \Heyosseus\Vacuum\Values\TableProfile) {
            // A table somebody dropped, or a URL somebody typed. Neither is an error
            // worth a stack trace.
            throw new NotFoundHttpException("There is no table called {$schema}.{$table} on this connection.");
        }

        $qualified = $profile->qualifiedName();

        return Views::make('vacuum::table', [
            'table' => $profile,

            // Every index on this table, including the ones nothing reads, because
            // the point of the page is to show what the table costs and an index is
            // most of it.
            'indexes' => array_values(array_filter(
                $this->indexes->all(),
                static fn (IndexStatistic $index): bool => $index->schema === $schema && $index->table === $table,
            )),

            // What the advisor already said, narrowed to this table. The page does
            // not judge anything itself: a second opinion that disagreed with the
            // dashboard would be a bug, not a feature.
            'findings' => array_values(array_filter(
                $this->advisor->findings(),
                static fn (Finding $finding): bool => $finding->table === $qualified,
            )),

            'capabilities' => $this->capabilities,
            'connection' => $this->connections->resolve()->getName() ?? 'unnamed',
        ]);
    }
}
