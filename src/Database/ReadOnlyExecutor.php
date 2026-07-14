<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Database;

use Heyosseus\Vacuum\Exceptions\NestedTransaction;

/**
 * Every statement Vacuum sends to the inspected database goes through here.
 *
 * The statement runs inside a read-only transaction that is always rolled back,
 * so PostgreSQL — not a keyword filter — is what refuses a write. Keyword
 * filters are defeated by data-modifying CTEs, functions with side effects and
 * multi-statement payloads; a READ ONLY transaction is not.
 */
final readonly class ReadOnlyExecutor
{
    public function __construct(private ConnectionResolver $resolver) {}

    /**
     * @param  array<int|string, mixed>  $bindings
     * @return list<array<string, mixed>>
     *
     * @throws NestedTransaction
     */
    public function select(string $sql, array $bindings = [], int $timeoutMilliseconds = 5_000): array
    {
        $connection = $this->resolver->resolve();

        if ($connection->transactionLevel() > 0) {
            // Inside an open transaction, beginTransaction() only opens a
            // savepoint, and a savepoint cannot be made read-only. Refusing is
            // the only honest answer: we cannot offer the guarantee.
            throw NestedTransaction::onConnection($connection->getName() ?? 'unnamed');
        }

        // The transaction must be one Laravel knows about. Laravel's
        // LostConnectionDetector treats PostgreSQL's "read only sql transaction"
        // error as a lost connection, and Connection::run() would then reconnect
        // and retry the statement outside the transaction -- committing the very
        // write we rejected. It only skips that retry while transactions >= 1.
        $connection->beginTransaction();

        try {
            $connection->statement('SET TRANSACTION READ ONLY');

            // statement_timeout takes no bindings, so the value is constrained by
            // the int type rather than by escaping. SET LOCAL scopes it to this
            // transaction, leaving the pooled connection untouched afterwards.
            $connection->statement("SET LOCAL statement_timeout = {$timeoutMilliseconds}");

            $rows = $connection->select($sql, $bindings);
        } finally {
            $connection->rollBack();
        }

        return array_values(array_map($this->toRow(...), $rows));
    }

    /**
     * Laravel types a selected row as mixed; PostgreSQL hands back a stdClass
     * whose properties are the column names.
     *
     * @return array<string, mixed>
     */
    private function toRow(mixed $row): array
    {
        $columns = [];

        foreach ((array) $row as $column => $value) {
            $columns[(string) $column] = $value;
        }

        return $columns;
    }
}
