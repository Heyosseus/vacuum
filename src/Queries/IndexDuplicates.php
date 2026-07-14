<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Queries;

use Heyosseus\Vacuum\Database\ReadOnlyExecutor;
use Heyosseus\Vacuum\Support\Cast;
use Heyosseus\Vacuum\Support\IgnoredSchemas;
use Heyosseus\Vacuum\Support\SqlRepository;
use Heyosseus\Vacuum\Values\IndexDuplicate;

/**
 * Finds indexes that are exact copies of another index on the same table.
 *
 * The grouping is done by PostgreSQL rather than in PHP: deciding which of a group
 * to keep is a window function, and pulling every index into memory to compare
 * them here would be the same work done worse.
 */
final readonly class IndexDuplicates
{
    private const string STATEMENT = 'duplicate_indexes';

    public function __construct(
        private ReadOnlyExecutor $executor,
        private SqlRepository $sql,
        private IgnoredSchemas $ignored,
    ) {}

    /**
     * @return list<IndexDuplicate>
     */
    public function all(): array
    {
        $ignored = implode(',', $this->ignored->all());

        return array_map(
            $this->toDuplicate(...),
            $this->executor->select($this->sql->get(self::STATEMENT), [$ignored]),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function toDuplicate(array $row): IndexDuplicate
    {
        return new IndexDuplicate(
            schema: Cast::text($row['schemaname'] ?? null),
            table: Cast::text($row['tablename'] ?? null),
            name: Cast::text($row['indexname'] ?? null),
            bytes: Cast::integer($row['index_bytes'] ?? null),
            definition: Cast::text($row['definition'] ?? null),
            duplicateOf: Cast::text($row['keeper'] ?? null),
            constrains: Cast::boolean($row['constrains'] ?? null),
        );
    }
}
