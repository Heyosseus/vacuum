<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Queries;

use Heyosseus\Vacuum\Database\ReadOnlyExecutor;
use Heyosseus\Vacuum\Support\Cast;
use Heyosseus\Vacuum\Support\IgnoredSchemas;
use Heyosseus\Vacuum\Support\SqlRepository;
use Heyosseus\Vacuum\Values\IndexDefinition;

/**
 * Every index in the database, described by what it could serve.
 *
 * Deliberately the whole set rather than one table's, for the same reason
 * Columns is: the schema rules ask questions of the shape "does any index on
 * this table lead with these two columns", and answering that one table at a
 * time would be a query per table.
 */
final readonly class IndexColumns
{
    private const string STATEMENT = 'index_columns';

    public function __construct(
        private ReadOnlyExecutor $executor,
        private SqlRepository $sql,
        private IgnoredSchemas $ignored,
    ) {}

    /**
     * @return list<IndexDefinition>
     */
    public function all(): array
    {
        $ignored = implode(',', $this->ignored->all());

        return array_map(
            $this->toDefinition(...),
            $this->executor->select($this->sql->get(self::STATEMENT), [$ignored]),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function toDefinition(array $row): IndexDefinition
    {
        $columns = Cast::text($row['columns'] ?? null);

        return new IndexDefinition(
            schema: Cast::text($row['schemaname'] ?? null),
            table: Cast::text($row['tablename'] ?? null),
            name: Cast::text($row['indexname'] ?? null),
            columns: $columns === '' ? [] : explode(',', $columns),
            unique: Cast::boolean($row['isunique'] ?? null),
            valid: Cast::boolean($row['isvalid'] ?? null),
            partial: Cast::boolean($row['ispartial'] ?? null),
            method: Cast::text($row['method'] ?? null),
        );
    }
}
