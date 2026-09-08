<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Values;

/**
 * One index, described by its shape rather than by how much it has been used.
 *
 * IndexStatistic answers "has anything read this", which a database that came
 * into existence ninety seconds ago cannot answer at all. This answers "what
 * would this index be able to serve", which is true the moment the migration
 * finishes and is the only question a schema rule can honestly ask.
 *
 * @api Public API. Its shape is covered by the package version from 1.1 onward.
 */
final readonly class IndexDefinition
{
    /**
     * @param  list<string>  $columns  The key columns in indkey order. An INCLUDE payload is
     *                                 excluded: it is stored in the leaf and cannot be searched,
     *                                 so it is not part of what the index can serve.
     * @param  bool  $partial  Whether the index has a WHERE clause. A partial index serves only
     *                         the rows its predicate admits, so it does not answer a general
     *                         lookup on its leading columns and no rule here may treat it as if
     *                         it did.
     */
    public function __construct(
        public string $schema,
        public string $table,
        public string $name,
        public array $columns,
        public bool $unique,
        public bool $valid,
        public bool $partial,
        public string $method,
    ) {}

    public function qualifiedName(): string
    {
        return $this->schema.'.'.$this->name;
    }

    /**
     * Whether these are the LEADING columns of this index, in this order.
     *
     * Not set membership, which is the mistake this method exists to prevent. An
     * index on (status, customer_id) contains customer_id and cannot serve a
     * lookup on it; one on (customer_id, status) can. The two are identical as
     * sets and only one of them is useful.
     */
    public function leadsWith(string ...$columns): bool
    {
        return array_slice($this->columns, 0, count($columns)) === array_values($columns);
    }
}
