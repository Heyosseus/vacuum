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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View as Views;
use InvalidArgumentException;

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
            try {
                $blockCount = $this->heapPages->blockCount($schema, $table);

                if ($pageAvailability->available) {
                    $page = $this->heapPages->explore($schema, $table, $block);
                }
            } catch (InvalidArgumentException $exception) {
                // Out of range, or a table that does not exist. Either way it is
                // the reader's URL that is wrong, not the server, and the panel
                // below says so rather than the framework's own error page.
                $error = $exception->getMessage();
            }

            // Deliberately its own try: a block that does not exist must not
            // hide the row versions, which do not depend on the block at all
            // and need no extension to read.
            try {
                $rowVersions = $this->rowVersions->explore($schema, $table);
            } catch (InvalidArgumentException) {
                // The same missing relation the block lookup above already
                // reported.
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
