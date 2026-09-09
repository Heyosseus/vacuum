<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Schema;

/**
 * Where in the application a finding was introduced.
 *
 * Deliberately not part of Finding. Finding is frozen public API and it
 * describes the database, and the database has no files -- PostgreSQL has never
 * heard of a migration. This is the join, and it is made at the point of
 * display, by the console, which is the only layer that has any business knowing
 * the application has a filesystem at all.
 */
final readonly class SourceLocation
{
    public function __construct(
        public string $file,
        public int $line,
    ) {}
}
