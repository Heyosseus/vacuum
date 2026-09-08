<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor\Rules;

use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\SchemaRule;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Support\Identifier;
use Heyosseus\Vacuum\Values\TableSchema;

/**
 * Finds json columns where jsonb was almost certainly meant.
 *
 * PostgreSQL has two of them and they are not variations on a theme. json keeps
 * the document as text, exactly as it arrived, whitespace and duplicate keys and
 * key order intact, and reparses the whole thing on every single access. jsonb
 * parses once on write into a binary form that can be indexed, and a GIN index
 * over it can answer a containment query without reading the row.
 *
 * Laravel's $table->json() maps to json on PostgreSQL, so this is usually not a
 * decision anybody made -- which is exactly why it is worth saying.
 *
 * Info, not a warning. Keeping the document byte-for-byte is a real requirement
 * for a signed payload or an audit record, and a build that went red over it
 * would be a build people learn to ignore.
 */
final readonly class JsonNotJsonb implements SchemaRule
{
    /**
     * @return list<Finding>
     */
    public function inspect(TableSchema $table): array
    {
        $findings = [];

        foreach ($table->columns as $column) {
            if ($column->type !== 'json') {
                continue;
            }

            $findings[] = new Finding(
                rule: 'json-not-jsonb',
                subject: $table->qualifiedName().'.'.$column->name,
                severity: Severity::Info,
                summary: "{$column->name} is json rather than jsonb. On PostgreSQL those are different types "
                    .'with different costs, and Laravel\'s $table->json() picks this one.',
                impact: 'A json column stores the document as text and has to reparse all of it on every '
                    .'access, however small the part being read. It also cannot carry a GIN index, so a '
                    .'containment or key-existence query over it reads every row. jsonb parses once on write '
                    .'and indexes. The reason to keep json is if the exact bytes matter -- a signed payload, '
                    .'an audit record -- because jsonb normalises whitespace, key order and duplicate keys.',
                remediation: 'ALTER TABLE '.Identifier::qualified($table->schema, $table->table)
                    .' ALTER COLUMN '.Identifier::quote($column->name).' TYPE jsonb USING '
                    .Identifier::quote($column->name).'::jsonb;',
                evidence: "{$column->name} json",
                table: $table->qualifiedName(),
            );
        }

        return $findings;
    }
}
