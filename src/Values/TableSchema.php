<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Values;

/**
 * One table's shape: its columns, its constraints and its indexes together.
 *
 * This is the subject a schema rule is given, and the grouping is what keeps the
 * package's rule design intact. Several schema questions are cross-referencing
 * ones -- a missing primary key is about the table and its constraints, an
 * unindexed morphs pair is about its columns and its indexes -- and handing a
 * rule the whole database to answer them would be exactly the coupling the
 * Inspection contract exists to prevent. One table is the smallest subject that
 * can answer all of them, so it is the subject.
 *
 * @api Public API. Its shape is covered by the package version from 1.1 onward.
 */
final readonly class TableSchema
{
    /**
     * @param  list<Column>  $columns
     * @param  list<Constraint>  $constraints
     * @param  list<IndexDefinition>  $indexes
     */
    public function __construct(
        public string $schema,
        public string $table,
        public array $columns,
        public array $constraints,
        public array $indexes,
    ) {}

    public function qualifiedName(): string
    {
        return $this->schema.'.'.$this->table;
    }

    public function column(string $name): ?Column
    {
        foreach ($this->columns as $column) {
            if ($column->name === $name) {
                return $column;
            }
        }

        return null;
    }

    public function primaryKey(): ?Constraint
    {
        foreach ($this->constraints as $constraint) {
            if ($constraint->kind === 'p') {
                return $constraint;
            }
        }

        return null;
    }

    /**
     * @return list<Constraint>
     */
    public function foreignKeys(): array
    {
        $keys = [];

        foreach ($this->constraints as $constraint) {
            if ($constraint->isForeignKey()) {
                $keys[] = $constraint;
            }
        }

        return $keys;
    }

    /**
     * Whether some index on this table could serve a lookup on these columns.
     *
     * A partial index does not count, because it holds only the rows its
     * predicate admits and the lookup being asked about is a general one. An
     * invalid index does not count either: every write maintains it and no query
     * is allowed to use it, so treating it as coverage would hide the problem
     * behind the very thing that is also causing one.
     */
    public function hasIndexLeadingWith(string ...$columns): bool
    {
        foreach ($this->indexes as $index) {
            if ($index->partial) {
                continue;
            }

            if (! $index->valid) {
                continue;
            }

            if ($index->leadsWith(...$columns)) {
                return true;
            }
        }

        return false;
    }
}
