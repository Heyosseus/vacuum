<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Queries;

use Heyosseus\Vacuum\Database\ReadOnlyExecutor;
use Heyosseus\Vacuum\Support\Cast;
use Heyosseus\Vacuum\Support\IgnoredSchemas;
use Heyosseus\Vacuum\Support\SqlRepository;
use Heyosseus\Vacuum\Values\Column;

/**
 * Every column in the database, so a lesson can recognise the Eloquent
 * conventions a schema was built with.
 *
 * Deliberately the whole set rather than one table's: the lessons that use this
 * ask questions of the shape "which of my tables have a deleted_at", and
 * answering that one table at a time would be a query per table.
 */
final readonly class Columns
{
    private const string STATEMENT = 'columns';

    public function __construct(
        private ReadOnlyExecutor $executor,
        private SqlRepository $sql,
        private IgnoredSchemas $ignored,
    ) {}

    /**
     * @return list<Column>
     */
    public function all(): array
    {
        $ignored = implode(',', $this->ignored->all());

        return array_map(
            $this->toColumn(...),
            $this->executor->select($this->sql->get(self::STATEMENT), [$ignored]),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function toColumn(array $row): Column
    {
        return new Column(
            schema: Cast::text($row['schemaname'] ?? null),
            table: Cast::text($row['tablename'] ?? null),
            name: Cast::text($row['columnname'] ?? null),
            type: Cast::text($row['datatype'] ?? null),
            nullable: Cast::boolean($row['nullable'] ?? null),
        );
    }
}
