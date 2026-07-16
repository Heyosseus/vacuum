<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\History;

/**
 * One measurement gathered for a snapshot, before it becomes a row. The writer
 * turns a list of these into vacuum_snapshot_metrics; keeping them a plain value
 * first is what lets the collector be tested without a database to write to.
 */
final readonly class CollectedMetric
{
    public function __construct(
        public MetricKind $kind,
        public string $object,
        public ?float $value,
        public ?float $value2 = null,
    ) {}
}
