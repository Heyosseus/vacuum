<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Values;

/**
 * A shape of query PostgreSQL has been running, and what it has cost.
 *
 * Not a query: pg_stat_statements normalises the parameters away, so what is
 * stored is every SELECT ... WHERE id = $1 the application has ever run, added up
 * as one. That is what makes the totals meaningful and the statement unrunnable.
 */
final readonly class Statement
{
    public function __construct(
        public string $queryId,
        public string $sql,
        public int $calls,
        public float $totalMilliseconds,
        public float $meanMilliseconds,
        public int $rows,
    ) {}
}
