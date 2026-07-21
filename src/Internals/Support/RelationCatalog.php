<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Internals\Support;

use Heyosseus\Vacuum\Database\ReadOnlyExecutor;
use Heyosseus\Vacuum\Support\Identifier;
use InvalidArgumentException;

/**
 * Confirms a schema and table actually name a relation in the catalog before
 * either is ever quoted into a statement.
 *
 * Every explorer eventually needs the caller's schema and table turned into
 * SQL: a FROM clause, or the text argument a pageinspect function casts to
 * regclass. A name PostgreSQL itself has just vouched for is safe to quote;
 * a caller's raw string, taken from a URL, never is.
 */
final readonly class RelationCatalog
{
    /**
     * The relkinds that store heap pages: an ordinary table, a materialized
     * view, and a TOAST table. Only these have tuples laid out the way
     * heap_page_items reads them.
     *
     * @var list<string>
     */
    private const array STORES_HEAP = ['r', 'm', 't'];

    /**
     * What the other kinds are, for an error message that tells the reader
     * something rather than merely refusing.
     *
     * @var array<string, string>
     */
    private const array KINDS = [
        'i' => 'an index',
        'I' => 'a partitioned index',
        'S' => 'a sequence',
        'v' => 'a view',
        'f' => 'a foreign table',
        'c' => 'a composite type',
    ];

    public function __construct(private ReadOnlyExecutor $executor) {}

    /**
     * The quoted, schema-qualified name of the relation, or an exception
     * when no such relation exists in the catalog -- a dropped table or a
     * mistyped URL should never reach as far as a system function.
     *
     * Existing in pg_class is not enough. pg_class holds indexes, views,
     * sequences and composite types alongside tables, and what happens to
     * each of them downstream is worse the further it gets: a view has no
     * ctid and errors, an index *succeeds* -- get_raw_page and
     * heap_page_items will happily read a B-tree page and hand back index
     * tuples decoded as though they were heap tuples, which renders as a
     * full, confident, entirely meaningless panel. Being refused here is the
     * only outcome that is honest, so the kind is checked before the name is
     * ever quoted into a statement.
     *
     * @throws InvalidArgumentException
     */
    public function resolve(string $schema, string $table): string
    {
        $rows = $this->executor->select(
            'SELECT c.relkind::text AS relkind FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace '
            .'WHERE n.nspname = ? AND c.relname = ?',
            [$schema, $table],
        );

        if ($rows === []) {
            throw new InvalidArgumentException("No such relation: {$schema}.{$table}");
        }

        $kind = is_string($rows[0]['relkind'] ?? null) ? $rows[0]['relkind'] : '';

        if ($kind === 'p') {
            throw new InvalidArgumentException(
                "{$schema}.{$table} is a partitioned table. It has no storage of its own -- every row lives in "
                .'one of its partitions -- so there is no page here to open. Choose a partition instead.',
            );
        }

        if (! in_array($kind, self::STORES_HEAP, true)) {
            $what = self::KINDS[$kind] ?? 'not a table';

            throw new InvalidArgumentException(
                "{$schema}.{$table} is {$what}, which stores no heap pages. This explorer reads the tuple "
                .'layout of tables, materialized views and TOAST tables.',
            );
        }

        return Identifier::qualified($schema, $table);
    }
}
