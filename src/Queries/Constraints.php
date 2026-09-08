<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Queries;

use Heyosseus\Vacuum\Database\ReadOnlyExecutor;
use Heyosseus\Vacuum\Support\Cast;
use Heyosseus\Vacuum\Support\IgnoredSchemas;
use Heyosseus\Vacuum\Support\SqlRepository;
use Heyosseus\Vacuum\Values\Constraint;

/**
 * Every primary key, unique constraint and foreign key in the database, with
 * the one fact about each that the catalog does not hand over directly:
 * whether an index actually covers it.
 *
 * The coverage test is done in SQL rather than by pulling every index into PHP
 * and comparing column lists here, for the same reason IndexDuplicates groups
 * in the server: it is a prefix comparison over an int2vector, and PostgreSQL
 * is better at that than an array_slice loop would be.
 */
final readonly class Constraints
{
    private const string STATEMENT = 'constraints';

    public function __construct(
        private ReadOnlyExecutor $executor,
        private SqlRepository $sql,
        private IgnoredSchemas $ignored,
    ) {}

    /**
     * @return list<Constraint>
     */
    public function all(): array
    {
        $ignored = implode(',', $this->ignored->all());

        return array_map(
            $this->toConstraint(...),
            $this->executor->select($this->sql->get(self::STATEMENT), [$ignored]),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function toConstraint(array $row): Constraint
    {
        $columns = Cast::text($row['columns'] ?? null);
        $columnTypes = Cast::text($row['columntypes'] ?? null);
        $referencedColumnTypes = Cast::text($row['referencedcolumntypes'] ?? null);

        return new Constraint(
            schema: Cast::text($row['schemaname'] ?? null),
            table: Cast::text($row['tablename'] ?? null),
            name: Cast::text($row['constraintname'] ?? null),
            kind: Cast::text($row['kind'] ?? null),
            // constraints.sql joins these three lists with a newline rather than a
            // comma: format_type renders numeric(10,2) with a comma already inside
            // it, and comma-splitting that would break one type into two. Neither
            // format_type's output nor a PostgreSQL identifier can contain a
            // newline, so it is a safe delimiter, and it is internal to this class
            // -- nothing downstream sees it.
            columns: $columns === '' ? [] : explode("\n", $columns),
            referencedTable: Cast::text($row['referencedtable'] ?? null),
            indexed: Cast::boolean($row['indexed'] ?? null),
            columnTypes: $columnTypes === '' ? [] : explode("\n", $columnTypes),
            referencedColumnTypes: $referencedColumnTypes === '' ? [] : explode("\n", $referencedColumnTypes),
        );
    }
}
