<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Support;

use Heyosseus\Vacuum\Exceptions\MissingStatement;

/**
 * Reads the package's SQL from disk, once.
 *
 * Small queries live in the class that maps their result, where a renamed column
 * is one edit rather than two. The statements kept here are the ones nobody can
 * read inside a PHP string: the bloat estimate is a hundred lines of arithmetic
 * over the planner's own statistics, and it belongs in a file that a DBA can open
 * and check.
 */
final class SqlRepository
{
    /**
     * @var array<string, string>
     */
    private array $statements = [];

    public function __construct(private readonly string $directory) {}

    /**
     * A statement, in the dialect the server in front of us actually speaks.
     *
     * The catalogs drift between majors, and not always gently: PostgreSQL 17
     * did not move pg_stat_bgwriter's checkpoint columns to pg_stat_checkpointer
     * so much as delete them, so a statement written for 16 does not return
     * nulls on 17 -- it fails to parse. A name may therefore have a variant per
     * major, and the neutral file is the fallback for every version that does
     * not need one.
     */
    public function get(string $name, ?int $majorVersion = null): string
    {
        $key = $name.'@'.($majorVersion ?? 0);

        return $this->statements[$key] ??= $this->resolve($name, $majorVersion);
    }

    private function resolve(string $name, ?int $majorVersion): string
    {
        $this->guard($name);

        if ($majorVersion !== null) {
            $variant = $this->read($name.'.pg'.$majorVersion);

            if ($variant !== null) {
                return $variant;
            }
        }

        $sql = $this->read($name);

        if ($sql === null) {
            throw MissingStatement::named($name);
        }

        return $sql;
    }

    /**
     * Every name is one of the package's own constants. It is checked anyway:
     * the day a name is built from a request is the day this line matters, and
     * that day will not announce itself.
     */
    private function guard(string $name): void
    {
        if (in_array(preg_match('/^[a-z][a-z0-9_]*$/', $name), [0, false], true)) {
            throw MissingStatement::named($name);
        }
    }

    private function read(string $file): ?string
    {
        $sql = @file_get_contents($this->directory.DIRECTORY_SEPARATOR.$file.'.sql');

        return $sql === false ? null : $sql;
    }
}
