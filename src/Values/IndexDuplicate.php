<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Values;

/**
 * An index that is an exact copy of another index on the same table.
 *
 * It carries the name of the one being kept, because "this index is a duplicate"
 * is not an actionable sentence without "of that one".
 */
final readonly class IndexDuplicate
{
    /**
     * @param  string  $duplicateOf  The index PostgreSQL would keep of the group.
     * @param  bool  $constrains  Whether it is unique or a primary key, in which case a
     *                            constraint may depend on it and refuse the drop.
     */
    public function __construct(
        public string $schema,
        public string $table,
        public string $name,
        public int $bytes,
        public string $definition,
        public string $duplicateOf,
        public bool $constrains,
    ) {}

    public function qualifiedName(): string
    {
        return $this->schema.'.'.$this->name;
    }
}
