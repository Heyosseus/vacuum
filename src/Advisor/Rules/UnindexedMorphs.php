<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor\Rules;

use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\SchemaRule;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Support\Identifier;
use Heyosseus\Vacuum\Values\Column;
use Heyosseus\Vacuum\Values\TableSchema;

/**
 * Finds polymorphic relation columns with no composite index behind them.
 *
 * $table->morphs('commentable') creates the pair and the index; the columns
 * written by hand, or a nullableMorphs() that a later migration replaced, often
 * arrive without it. A morph is always queried by both columns at once -- the
 * relation cannot resolve a row from the id alone, because the id is only unique
 * within a type -- so a lookup with no index on the pair reads the whole table
 * every time a morphed relation is loaded.
 *
 * The order is not incidental. The index has to lead with the type, because that
 * is the column with the low cardinality and it is the one the relation fixes
 * first. An index on (id, type) contains both columns and answers a question
 * nobody is asking.
 *
 * The pair is recognised in the schema, by convention, exactly as the Learn
 * section does it: a string *_type beside an integer *_id. The catalog can prove
 * the two columns exist and cannot prove that morphs() is what wrote them.
 */
final readonly class UnindexedMorphs implements SchemaRule
{
    /**
     * @return list<Finding>
     */
    public function inspect(TableSchema $table): array
    {
        $findings = [];

        foreach ($table->columns as $column) {
            if (! str_ends_with($column->name, '_type')) {
                continue;
            }

            // A morph type column holds a class name, so it is a string. text and
            // character varying(n) are both what Laravel might have written; an
            // integer *_type column is somebody else's convention entirely.
            if ($column->type !== 'text' && ! str_starts_with($column->type, 'character varying')) {
                continue;
            }

            $name = substr($column->name, 0, -strlen('_type'));
            $id = $table->column($name.'_id');

            if (! $id instanceof Column) {
                continue;
            }

            if (! in_array($id->type, ['bigint', 'integer'], true)) {
                continue;
            }

            if ($table->hasIndexLeadingWith($column->name, $id->name)) {
                continue;
            }

            $findings[] = new Finding(
                rule: 'unindexed-morphs',
                subject: $table->qualifiedName().'.'.$column->name,
                severity: Severity::Warning,
                summary: "The polymorphic pair ({$column->name}, {$id->name}) has no index leading with both "
                    .'columns in that order.',
                impact: 'A morphed relation is always resolved by type and id together -- the id alone is only '
                    ."unique within a type -- so every load of this relation reads all of {$table->table}. An "
                    .'index that leads with the id instead does not help: the relation fixes the type first, '
                    .'and that is the column the index has to start with.',
                remediation: 'CREATE INDEX CONCURRENTLY ON '.Identifier::qualified($table->schema, $table->table)
                    .' ('.Identifier::quote($column->name).', '.Identifier::quote($id->name).');',
                evidence: "{$column->name} {$column->type}, {$id->name} {$id->type}",
                table: $table->qualifiedName(),
            );
        }

        return $findings;
    }
}
