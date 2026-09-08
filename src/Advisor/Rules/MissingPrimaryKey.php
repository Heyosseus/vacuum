<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor\Rules;

use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\SchemaRule;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Support\Identifier;
use Heyosseus\Vacuum\Values\Column;
use Heyosseus\Vacuum\Values\Constraint;
use Heyosseus\Vacuum\Values\TableSchema;

/**
 * Finds tables with no primary key.
 *
 * Usually a pivot: $table->foreignId('role_id')->foreignId('user_id') and a
 * unique index over the pair, which is a perfectly good key that nobody declared
 * as one. The consequences are not aesthetic. Logical replication cannot
 * replicate an update or a delete against a table with no replica identity, and
 * the default replica identity is the primary key -- so such a table silently
 * breaks a replication setup that works for everything else. Eloquent cannot
 * update or delete a model it cannot address by key. And nothing stops a bug, a
 * retried job or a double-submitted form from writing the same row twice, after
 * which telling the copies apart is guesswork.
 *
 * Where a unique constraint already exists, the remedy is to promote it. Where
 * one does not, this offers no statement at all: which columns identify a row is
 * a question about the domain, and a guess dressed up as a migration is worse
 * advice than none.
 */
final readonly class MissingPrimaryKey implements SchemaRule
{
    /**
     * @return list<Finding>
     */
    public function inspect(TableSchema $table): array
    {
        if ($table->primaryKey() instanceof Constraint) {
            return [];
        }

        $promotable = $this->promotable($table);

        return [new Finding(
            rule: 'missing-primary-key',
            subject: $table->qualifiedName(),
            severity: Severity::Warning,
            summary: $promotable instanceof Constraint
                ? "This table has no primary key. It does have the unique constraint {$promotable->name}, "
                    .'which is already a key in everything but name.'
                : 'This table has no primary key and no unique constraint that could become one.',
            impact: 'Logical replication cannot replicate an update or a delete against a table with no '
                .'replica identity, and the default replica identity is the primary key -- so this table '
                .'quietly breaks a replication setup that works for every other table. Eloquent cannot '
                .'update or delete a model it has no key to address. And nothing prevents a retried job or '
                .'a resubmitted form from writing the same row twice, after which telling the copies apart '
                .'is guesswork.',
            remediation: $promotable instanceof Constraint
                ? 'ALTER TABLE '.Identifier::qualified($table->schema, $table->table).' ADD PRIMARY KEY ('
                    .implode(', ', array_map(Identifier::quote(...), $promotable->columns)).');'
                : null,
            evidence: $promotable instanceof Constraint
                ? 'UNIQUE ('.implode(', ', $promotable->columns).')'
                : null,
            table: $table->qualifiedName(),
        )];
    }

    /**
     * A unique constraint over columns that are all NOT NULL, which is what a
     * primary key requires. A unique constraint on a nullable column is not a
     * key: PostgreSQL treats nulls as distinct, so it permits many rows that a
     * primary key would refuse, and promoting it would fail.
     */
    private function promotable(TableSchema $table): ?Constraint
    {
        foreach ($table->constraints as $constraint) {
            if ($constraint->kind !== 'u') {
                continue;
            }

            if ($constraint->columns === []) {
                continue;
            }

            foreach ($constraint->columns as $name) {
                $column = $table->column($name);

                if (! $column instanceof Column) {
                    continue 2;
                }

                if ($column->nullable) {
                    continue 2;
                }
            }

            return $constraint;
        }

        return null;
    }
}
