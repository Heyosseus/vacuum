<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Internals\Explorers;

use Heyosseus\Vacuum\Database\ReadOnlyExecutor;
use Heyosseus\Vacuum\Internals\Availability;
use Heyosseus\Vacuum\Internals\Decoders\Ctid;
use Heyosseus\Vacuum\Internals\Explorer;
use Heyosseus\Vacuum\Internals\Support\RelationCatalog;
use Heyosseus\Vacuum\Internals\Values\RowVersion;
use Heyosseus\Vacuum\Support\Cast;
use Illuminate\Contracts\Config\Repository;

/**
 * Shows where each physical version of a row currently lives, and which
 * transaction wrote it, using only the ctid, xmin and xmax every table
 * already carries as hidden system columns.
 *
 * Needing no extension and no privilege beyond an ordinary SELECT is the
 * whole point of this explorer: it is the one that still works on managed
 * PostgreSQL -- RDS, Cloud SQL, Azure, Supabase, Neon -- where pageinspect
 * cannot run at all, so it is the explorer every reader can open first.
 */
final readonly class RowVersions implements Explorer
{
    public function __construct(
        private ReadOnlyExecutor $executor,
        private RelationCatalog $relations,
        private Repository $config,
    ) {}

    public function availability(): Availability
    {
        return (bool) $this->config->get('vacuum.internals.enabled', false)
            ? Availability::available()
            : Availability::disabled();
    }

    /**
     * Every row version currently on disk for the table, oldest physical
     * position first.
     *
     * @return list<RowVersion>
     */
    public function explore(string $schema, string $table, int $limit = 50): array
    {
        $relation = $this->relations->resolve($schema, $table);

        // The relation here is not the caller's string: resolve() has just
        // proven it names a real relation in the catalog and quoted it, so
        // building the FROM clause from it is not interpolating a caller's
        // value into SQL. The row limit is the caller's value, and it is
        // bound rather than joined into the statement.
        $sql = sprintf(
            'SELECT ctid::text AS ctid, xmin::text AS xmin, xmax::text AS xmax FROM %s ORDER BY ctid LIMIT ?',
            $relation,
        );

        return array_map($this->toVersion(...), $this->executor->select($sql, [$limit]));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function toVersion(array $row): RowVersion
    {
        $ctid = Cast::text($row['ctid'] ?? null);
        $parsed = Ctid::parse($ctid);
        $xmax = Cast::text($row['xmax'] ?? null);

        return new RowVersion(
            ctid: $ctid,
            block: $parsed['block'],
            offset: $parsed['offset'],
            xmin: Cast::text($row['xmin'] ?? null),
            xmax: $xmax,

            // xmax is a transaction id, and PostgreSQL's own numbering starts
            // counting at 3, so 0 is not simply falsy -- it is the specific
            // sentinel meaning nothing has written xmax here. That is all this
            // can say: a non-zero xmax may be a deleter, a locker, or an
            // aborted transaction, and telling them apart needs the infomask,
            // which no amount of selecting system columns will hand over.
            untouched: $xmax === '0',
        );
    }
}
