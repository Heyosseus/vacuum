<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Queries;

use Heyosseus\Vacuum\Values\TableSchema;

/**
 * Every table's shape, however it was arrived at.
 *
 * The seam exists so that a schema rule can be put to a fixed set of tables
 * without a database behind it. Reading the catalog is what TableSchemas does and
 * what production always wants; a test that needs two tables with a known shape
 * should not have to create them, migrate them and drop them again to find out
 * whether a rule fires.
 */
interface Schemas
{
    /**
     * @return list<TableSchema>
     */
    public function all(): array;
}
