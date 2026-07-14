<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Support;

/**
 * Quotes a schema-qualified identifier the way PostgreSQL does.
 *
 * These identifiers only ever reach the user as remediation SQL for them to
 * read and decide on, but a suggestion that would not parse is a broken
 * suggestion — and table names can legally contain quotes and spaces.
 */
final class Identifier
{
    public static function qualified(string $schema, string $name): string
    {
        return self::quote($schema).'.'.self::quote($name);
    }

    public static function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    /**
     * The same name as a string literal, for the catalog views that compare
     * schemaname and relname as text rather than taking an identifier.
     */
    public static function literal(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
