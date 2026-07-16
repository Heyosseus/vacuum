<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Values;

/**
 * What a statement in the console produced.
 */
final readonly class ConsoleResult
{
    /**
     * @param  list<string>  $columns
     * @param  list<array<string, mixed>>  $rows  Never more than the configured max_rows.
     * @param  bool  $capped  Whether PostgreSQL held at least one row more than these.
     *
     * There is deliberately no count of the rows the statement would have matched.
     * Learning that means letting the server produce all of them, which is precisely
     * what the cap exists to prevent: the console asks for one row more than it will
     * show, and so the only honest thing it can say about a capped result is that
     * there was more. A number here would have to be either a lie or a full scan.
     */
    public function __construct(
        public array $columns,
        public array $rows,
        public bool $capped,
        public float $milliseconds,
    ) {}
}
