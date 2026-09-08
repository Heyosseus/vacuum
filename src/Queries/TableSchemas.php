<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Queries;

use Heyosseus\Vacuum\Values\Column;
use Heyosseus\Vacuum\Values\Constraint;
use Heyosseus\Vacuum\Values\IndexDefinition;
use Heyosseus\Vacuum\Values\TableSchema;

/**
 * Every table's shape, assembled from three whole-database catalog reads.
 *
 * Three queries for the whole run rather than three per table. The grouping is
 * done here, in PHP, because it is a join on a string key over a few thousand
 * rows and asking PostgreSQL to do it would mean either one enormous query whose
 * result is mostly repetition, or a query per table.
 *
 * The set of tables comes from the columns: a table with no columns is not a
 * table anybody has, and every table that exists has at least one.
 */
final readonly class TableSchemas implements Schemas
{
    public function __construct(
        private Columns $columns,
        private Constraints $constraints,
        private IndexColumns $indexes,
    ) {}

    /**
     * @return list<TableSchema>
     */
    public function all(): array
    {
        /** @var array<string, list<Column>> $columns */
        $columns = [];

        foreach ($this->columns->all() as $column) {
            $columns[$column->qualifiedName()][] = $column;
        }

        /** @var array<string, list<Constraint>> $constraints */
        $constraints = [];

        foreach ($this->constraints->all() as $constraint) {
            $constraints[$constraint->qualifiedName()][] = $constraint;
        }

        /** @var array<string, list<IndexDefinition>> $indexes */
        $indexes = [];

        foreach ($this->indexes->all() as $index) {
            $indexes[$index->schema.'.'.$index->table][] = $index;
        }

        $schemas = [];

        foreach ($columns as $qualified => $tableColumns) {
            $first = $tableColumns[0];

            $schemas[] = new TableSchema(
                schema: $first->schema,
                table: $first->table,
                columns: $tableColumns,
                constraints: $constraints[$qualified] ?? [],
                indexes: $indexes[$qualified] ?? [],
            );
        }

        return $schemas;
    }
}
