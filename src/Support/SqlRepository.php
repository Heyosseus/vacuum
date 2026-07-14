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

    public function get(string $name): string
    {
        return $this->statements[$name] ??= $this->read($name);
    }

    private function read(string $name): string
    {
        // Every name is one of the package's own constants. It is checked anyway:
        // the day a name is built from a request is the day this line matters, and
        // that day will not announce itself.
        if (in_array(preg_match('/^[a-z][a-z0-9_]*$/', $name), [0, false], true)) {
            throw MissingStatement::named($name);
        }

        $sql = @file_get_contents($this->directory.DIRECTORY_SEPARATOR.$name.'.sql');

        if ($sql === false) {
            throw MissingStatement::named($name);
        }

        return $sql;
    }
}
