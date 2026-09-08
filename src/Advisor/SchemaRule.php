<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor;

use Heyosseus\Vacuum\Values\TableSchema;

/**
 * A question worth asking of one table's shape, answerable the moment the
 * migrations finish and without a single row existing.
 *
 * This returns a list where every other rule contract in the package returns one
 * finding or null, and the difference is deliberate. A table with four unindexed
 * foreign keys has four problems, on four columns, each fixed by a different
 * statement and each belonging on a different line of a pull request. Collapsing
 * them into one finding with a four-line remediation would read acceptably in a
 * terminal and would be useless everywhere else.
 *
 * @api Public API. Its shape is covered by the package version from 1.1 onward.
 */
interface SchemaRule
{
    /**
     * @return list<Finding>
     */
    public function inspect(TableSchema $table): array;
}
