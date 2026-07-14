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
     * @param  list<array<string, mixed>>  $rows  Only as many as the configuration allows.
     * @param  int  $found  How many rows came back, which may be more than are shown.
     */
    public function __construct(
        public array $columns,
        public array $rows,
        public int $found,
        public float $milliseconds,
    ) {}

    public function truncated(): bool
    {
        return $this->found > count($this->rows);
    }
}
