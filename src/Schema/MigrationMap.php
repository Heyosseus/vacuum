<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Schema;

use Heyosseus\Vacuum\Advisor\Finding;

/**
 * Traces a finding back to the migration line that introduced it.
 *
 * PostgreSQL knows public.orders has an unindexed foreign key on customer_id and
 * has never heard of 2024_01_11_000000_create_orders_table.php. This is the join
 * that turns a line in a build log into an annotation on the diff a reviewer is
 * already reading, which is the difference between a tool people run and a tool
 * people notice.
 *
 * A finding the map cannot place is still reported, without a file and without a
 * line. That matters most on an application that has run `schema:dump`, where the
 * migrations were squashed into a .sql file and deleted -- and those are exactly
 * the long-lived applications with the most findings. The map degrades to silence
 * there rather than guessing, on the same principle as everything else here.
 */
final class MigrationMap
{
    /** @var array<string, SourceLocation>|null */
    private ?array $entries = null;

    public function __construct(
        private readonly string $directory,
        private readonly MigrationScanner $scanner,
    ) {}

    /**
     * A migration never writes a schema -- `Schema::create('orders', ...)`, not
     * `Schema::create('public.orders', ...)` -- unless the application is
     * multi-schema and deliberately qualifies it, so the scanner's entries are
     * ordinarily unqualified even when the finding is not. Reducing straight to
     * the unqualified key, as this used to, means `tenant.orders` and
     * `public.orders` collide on the one entry `orders` records: a finding in
     * the tenant schema anchors to the default schema's migration, which is
     * wrong precisely on the multi-schema and multi-tenant Postgres this
     * package's audience runs. The qualified key is tried first, so a migration
     * that does write the schema wins the collision it would otherwise lose to.
     */
    public function locate(Finding $finding): ?SourceLocation
    {
        if ($finding->table === null) {
            return null;
        }

        $entries = $this->entries();
        $qualified = $finding->table;
        $table = $this->unqualified($qualified);
        $column = $this->column($finding->subject, $qualified);

        if ($column !== null) {
            $onQualifiedTable = $entries[$qualified.'.'.$column] ?? null;

            if ($onQualifiedTable instanceof SourceLocation) {
                return $onQualifiedTable;
            }

            $onUnqualifiedTable = $entries[$table.'.'.$column] ?? null;

            if ($onUnqualifiedTable instanceof SourceLocation) {
                return $onUnqualifiedTable;
            }
        }

        return $entries[$qualified] ?? $entries[$table] ?? null;
    }

    /**
     * The part of a subject naming a column of the given table, or null when the
     * subject is not one -- a table-level finding, or an index finding whose
     * subject is the index rather than the table it belongs to.
     */
    private function column(string $subject, string $table): ?string
    {
        $prefix = $table.'.';

        return str_starts_with($subject, $prefix) ? substr($subject, strlen($prefix)) : null;
    }

    /**
     * Scanned once per run, and only when something asks.
     *
     * @return array<string, SourceLocation>
     */
    private function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        $entries = [];
        $files = glob(rtrim($this->directory, '/\\').DIRECTORY_SEPARATOR.'*.php');

        foreach ($files === false ? [] : $files as $file) {
            // A glob match that is not a readable file -- a directory named
            // something.php is the reproducible case -- fails differently by
            // platform: Windows returns false where Linux returns an empty
            // string. Coalescing the two is not tidiness, it is what keeps this
            // branch reachable on both, rather than covered on a laptop and dead
            // in CI.
            $source = @file_get_contents($file) ?: '';

            if ($source === '') {
                continue;
            }

            foreach ($this->scanner->scan($source) as $key => $line) {
                $entries[$key] ??= new SourceLocation($file, $line);
            }
        }

        return $this->entries = $entries;
    }

    private function unqualified(string $table): string
    {
        $dot = strrpos($table, '.');

        return $dot === false ? $table : substr($table, $dot + 1);
    }
}
