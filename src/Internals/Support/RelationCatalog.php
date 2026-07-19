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
    public function __construct(private ReadOnlyExecutor $executor) {}

    /**
     * The quoted, schema-qualified name of the relation, or an exception
     * when no such relation exists in the catalog -- a dropped table or a
     * mistyped URL should never reach as far as a system function.
     *
     * @throws InvalidArgumentException
     */
    public function resolve(string $schema, string $table): string
    {
        $rows = $this->executor->select(
            'SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace '
            .'WHERE n.nspname = ? AND c.relname = ?',
            [$schema, $table],
        );

        if ($rows === []) {
            throw new InvalidArgumentException("No such relation: {$schema}.{$table}");
        }

        return Identifier::qualified($schema, $table);
    }
}
