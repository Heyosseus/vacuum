<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Http\Controllers;

use Heyosseus\Vacuum\Database\ConnectionResolver;
use Heyosseus\Vacuum\Internals\Explorers\HeapPages;
use Heyosseus\Vacuum\Internals\Explorers\RowVersions;
use Heyosseus\Vacuum\Internals\Values\HeapPage;
use Heyosseus\Vacuum\Queries\TableStatistics;
use Heyosseus\Vacuum\Values\Capabilities;
use Heyosseus\Vacuum\Values\TableStatistic;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View as Views;
use InvalidArgumentException;
use Throwable;

/**
 * One real 8 kB heap page, opened block by block, so a reader can see a dead
 * tuple or a HOT chain the way PostgreSQL stores them rather than as a
 * summary. Everything the domain layer computes for a page lives in
 * {@see HeapPages} and {@see HeapPage}; this only turns a URL's schema,
 * table and block into the arguments those already know how to answer, and
 * turns their exceptions into a panel instead of a 500.
 *
 * Without a relation chosen, the page is a picker over every table on the
 * connection. Row versions read no extension and no elevated privilege, so
 * they are fetched independently of the deep, pageinspect-backed page read
 * and rendered even when that one cannot run.
 */
final readonly class InternalsController
{
    public function __construct(
        private HeapPages $heapPages,
        private RowVersions $rowVersions,
        private TableStatistics $tables,
        private Capabilities $capabilities,
        private ConnectionResolver $connections,
    ) {}

    public function __invoke(Request $request): View
    {
        $schema = $request->string('schema')->toString();
        $table = $request->string('table')->toString();
        $chosen = $schema !== '' && $table !== '' ? ['schema' => $schema, 'table' => $table] : null;

        $block = max(0, $request->integer('block', 0));
        $pageAvailability = $this->heapPages->availability();

        $page = null;
        $rowVersions = [];
        $blockCount = 0;
        $error = null;

        if ($chosen !== null) {
            // PostgreSQL refusing either read belongs in the panel and not in a
            // stack trace, and it is one answer however it arrives: pageinspect's
            // functions are superuser-restricted, a relation can be dropped
            // between the catalog lookup and the page read, a matview can exist
            // without having been populated, and a role's privileges can change
            // underneath a request. None of those is a bug in the page somebody
            // asked for, and every one of them used to be a 500.
            try {
                try {
                    $blockCount = $this->heapPages->blockCount($schema, $table);

                    if ($pageAvailability->available) {
                        $page = $this->heapPages->explore($schema, $table, $block);
                    }
                } catch (InvalidArgumentException $exception) {
                    // Out of range, a relation that does not exist, or one that
                    // stores no heap pages. The reader's URL is wrong rather than
                    // the server, and the panel below says so.
                    $error = $exception->getMessage();
                }

                // Its own try for the same reason it always was: a block that does
                // not exist must not hide the row versions, which do not depend on
                // the block at all and need no extension to read.
                try {
                    $rowVersions = $this->rowVersions->explore($schema, $table);
                } catch (InvalidArgumentException) {
                    // The same missing relation the block lookup already reported.
                }
            } catch (QueryException $exception) {
                // Deliberately overwriting anything the block lookup said. An
                // unpopulated matview reports both "block 0 of 0" and "has not
                // been populated", and only the second explains why.
                $error = $this->databaseMessage($exception);
            }
        }

        return Views::make('vacuum::internals', [
            'relations' => $chosen === null ? $this->relations() : [],
            'chosen' => $chosen,
            'block' => $block,
            'blockCount' => $blockCount,
            'page' => $page,
            'pageAvailability' => $pageAvailability,
            'rowVersions' => $rowVersions,
            'error' => $error,
            'capabilities' => $this->capabilities,
            'connection' => $this->connections->resolve()->getName() ?? 'unnamed',
        ]);
    }

    /**
     * What PostgreSQL said, without the statement and bindings Laravel appends
     * to a QueryException's message. The reader asked for a page, not for the
     * SQL this package writes on their behalf, and the driver's own sentence is
     * the part that tells them anything.
     */
    private function databaseMessage(QueryException $exception): string
    {
        $previous = $exception->getPrevious();

        return $previous instanceof Throwable ? $previous->getMessage() : $exception->getMessage();
    }

    /**
     * Every table on the connection, largest first by the rows it currently
     * carries. TableStatistics is what pg_stat_user_tables can say without a
     * per-table size query, and a live-plus-dead row count is close enough
     * to "largest" for a picker whose only job is to hand the reader
     * somewhere worth looking.
     *
     * @return list<TableStatistic>
     */
    private function relations(): array
    {
        $tables = $this->tables->all();

        usort(
            $tables,
            static fn (TableStatistic $a, TableStatistic $b): int => ($b->liveTuples + $b->deadTuples) <=> ($a->liveTuples + $a->deadTuples),
        );

        return $tables;
    }
}
