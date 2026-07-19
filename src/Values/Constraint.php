<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Values;

/**
 * One constraint, and whether an index stands behind it.
 *
 * The interesting case is a foreign key, because PostgreSQL is the one database
 * a Laravel developer is likely to meet that does not index them for you:
 * $table->foreignId('customer_id')->constrained() writes a constraint and no
 * index, and the cost of that shows up on the parent's deletes rather than on
 * the child's reads, which is why it goes unnoticed for so long.
 */
final readonly class Constraint
{
    /**
     * @param  string  $kind  pg_constraint.contype: 'p' primary key, 'u' unique, 'f' foreign key.
     * @param  list<string>  $columns  The constrained columns, in the order the constraint declares them.
     * @param  string  $referencedTable  The table a foreign key points at, or '' for the other kinds.
     * @param  bool  $indexed  Whether these columns are the LEADING columns of some index. A
     *                         trailing column of a composite index does not count, because such
     *                         an index cannot serve a lookup on it.
     */
    public function __construct(
        public string $schema,
        public string $table,
        public string $name,
        public string $kind,
        public array $columns,
        public string $referencedTable,
        public bool $indexed,
    ) {}

    public function qualifiedName(): string
    {
        return $this->schema.'.'.$this->table;
    }

    public function isForeignKey(): bool
    {
        return $this->kind === 'f';
    }
}
