<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Values;

/**
 * One column of one table.
 *
 * Carried for what it lets a lesson infer rather than for its own sake: the
 * presence of deleted_at, of updated_at, or of a jsonb column is how the Learn
 * section recognises the Eloquent idiom behind a schema without ever loading
 * the model that declared it.
 */
final readonly class Column
{
    /**
     * @param  string  $type  The type as format_type renders it -- 'jsonb', 'character varying(255)',
     *                        'timestamp without time zone' -- which is what a reader sees in psql.
     */
    public function __construct(
        public string $schema,
        public string $table,
        public string $name,
        public string $type,
        public bool $nullable,
    ) {}

    public function qualifiedName(): string
    {
        return $this->schema.'.'.$this->table;
    }
}
