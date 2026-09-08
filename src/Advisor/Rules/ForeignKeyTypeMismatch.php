<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor\Rules;

use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\SchemaRule;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Support\Identifier;
use Heyosseus\Vacuum\Values\Constraint;
use Heyosseus\Vacuum\Values\TableSchema;

/**
 * Finds foreign keys whose type does not match the key they reference.
 *
 * This is the mismatch that hides best, because nothing anywhere is marked
 * wrong. PostgreSQL accepts a foreign key from an integer to a bigint, creates
 * the constraint, and enforces it perfectly. The index on the parent exists. The
 * index on the child, if somebody made one, exists too. And the planner still
 * cannot use them for the referential check, because the comparison crosses
 * types -- so the check falls back to a scan, and the only symptom is that a
 * delete on the parent is slow, forever, for a reason that is not visible in the
 * schema, the query or the plan anybody thinks to look at.
 *
 * In Laravel it comes from mixing $table->integer('customer_id') with a parent
 * whose key is bigIncrements, which is what the framework's own default has been
 * since 5.8.
 *
 * This rule offers no remediation in two cases where the obvious one-line ALTER
 * would be wrong rather than merely unhelpful. The first is a composite key: the
 * finding used to rewrite column zero unconditionally, even when the mismatch
 * was actually in a later column, which takes the table's ACCESS EXCLUSIVE lock
 * and index rebuild for a column that already had the right type. The second is
 * a parent that is the *narrower* side of the mismatch -- a legacy
 * increments('id') parent referenced by a newer foreignId() child, most often.
 * Narrowing the child there would entrench the very 32-bit ceiling
 * int4-primary-key is warning about on the parent, and it would fail outright
 * the first time a value exceeds what the narrower type can hold. In both cases
 * the finding still fires, because the mismatch is real and still costs a scan
 * on every delete; only the ALTER is withheld, because this rule cannot know the
 * shape of the migration that would actually fix it.
 */
final readonly class ForeignKeyTypeMismatch implements SchemaRule
{
    /**
     * Rank, narrowest first, of the integer types this rule knows how to compare
     * for width. Anything outside this family -- or a mismatch where one side
     * is not in it, such as a foreign key crossing into text or uuid -- has no
     * narrow/wide relationship this rule can reason about, so it is left to the
     * existing child-widening advice.
     */
    private const array INTEGER_FAMILY_RANK = [
        'smallint' => 1,
        'integer' => 2,
        'bigint' => 3,
    ];

    /**
     * @return list<Finding>
     */
    public function inspect(TableSchema $table): array
    {
        $findings = [];

        foreach ($table->foreignKeys() as $key) {
            if ($key->columns === []) {
                continue;
            }

            // An empty list on either side is the query declining to answer, not
            // evidence of a mismatch. A finding built from missing evidence is how
            // a tool teaches people to stop reading it.
            if ($key->columnTypes === []) {
                continue;
            }

            if ($key->referencedColumnTypes === []) {
                continue;
            }

            if ($key->typesMatch()) {
                continue;
            }

            $leading = $key->columns[0];
            $here = implode(', ', $key->columnTypes);
            $there = implode(', ', $key->referencedColumnTypes);

            if (count($key->columns) > 1) {
                $remediation = null;
                $impact = $this->compositeImpact($key);
            } elseif ($this->parentIsNarrower($key->columnTypes[0], $key->referencedColumnTypes[0])) {
                $remediation = null;
                $impact = $this->narrowParentImpact($key);
            } else {
                $wanted = $key->referencedColumnTypes[0];
                $remediation = 'ALTER TABLE '.Identifier::qualified($table->schema, $table->table)
                    .' ALTER COLUMN '.Identifier::quote($leading)." TYPE {$wanted};";
                $impact = $this->widenChildImpact($key);
            }

            $findings[] = new Finding(
                rule: 'foreign-key-type-mismatch',
                subject: $table->qualifiedName().'.'.$leading,
                severity: Severity::Warning,
                summary: "The foreign key {$key->name} is {$here} and references {$there}. PostgreSQL accepts "
                    .'that and enforces it correctly; what it cannot do is use an index for the check.',
                impact: $impact,
                remediation: $remediation,
                evidence: "{$here} references {$there}",
                table: $table->qualifiedName(),
            );
        }

        return $findings;
    }

    /**
     * Whether the referenced (parent) column is the narrower side of an
     * integer-family mismatch -- smallint < integer < bigint.
     */
    private function parentIsNarrower(string $childType, string $parentType): bool
    {
        if (! isset(self::INTEGER_FAMILY_RANK[$childType])) {
            return false;
        }

        if (! isset(self::INTEGER_FAMILY_RANK[$parentType])) {
            return false;
        }

        return self::INTEGER_FAMILY_RANK[$parentType] < self::INTEGER_FAMILY_RANK[$childType];
    }

    private function widenChildImpact(Constraint $key): string
    {
        return 'Because the comparison crosses types, the planner cannot use the index on '
            ."{$key->referencedTable} to prove a row is unreferenced, so every delete and key update "
            .'on the parent falls back to a scan. Nothing is marked wrong anywhere: not the '
            .'constraint, not the index, not the plan you would think to look at. Correcting the '
            .'type will rewrite the table and take a lock for the duration, so it is a migration to '
            .'plan rather than one to run at five on a Friday.';
    }

    private function narrowParentImpact(Constraint $key): string
    {
        $parentType = $key->referencedColumnTypes[0];
        $childType = $key->columnTypes[0];

        return 'Because the comparison crosses types, the planner cannot use the index on '
            ."{$key->referencedTable} to prove a row is unreferenced, so every delete and key update "
            .'on the parent falls back to a scan. Here the parent is the narrow side of the mismatch: '
            ."{$key->referencedTable}'s column is {$parentType} and this one is {$childType}. The fix "
            .'has to start on the parent -- widening it is what actually raises the ceiling that '
            .'int4-primary-key already warns about there -- and it has to happen before anything about '
            .'this column changes: narrowing this column to match the parent would only entrench that '
            .'same ceiling, and widening the child on its own does not touch the parent at all, so it '
            .'would not help either. A constraint carries the referenced column\'s type but not its '
            .'name, so this rule cannot write that ALTER for you.';
    }

    /**
     * Names the columns of a composite key that disagree, rather than rewriting
     * whichever one happens to be first. A composite mismatch can have some
     * columns already correct and only one -- not necessarily the leading one --
     * wrong, and an ALTER aimed unconditionally at column zero would take the
     * table's lock and index rebuild to rewrite a column that already matched.
     */
    private function compositeImpact(Constraint $key): string
    {
        $pairs = [];

        foreach ($key->columns as $index => $column) {
            $childType = $key->columnTypes[$index] ?? null;
            $parentType = $key->referencedColumnTypes[$index] ?? null;

            if ($childType === null) {
                continue;
            }

            if ($parentType === null) {
                continue;
            }

            if ($childType === $parentType) {
                continue;
            }

            $pairs[] = "{$column} ({$childType} vs {$parentType})";
        }

        $disagreeing = implode(', ', $pairs);

        return 'Because the comparison crosses types, the planner cannot use the index on '
            ."{$key->referencedTable} to prove a row is unreferenced, so every delete and key update "
            .'on the parent falls back to a scan. This foreign key is composite, and only '
            ."{$disagreeing} disagree while the rest already match. Rewriting the leading column the "
            .'way a single-column mismatch would risks rewriting a column that is already correct and '
            .'missing the one that is not, so this finding names the columns rather than prescribing '
            .'an ALTER; work out which of them actually needs to change and write that migration by hand.';
    }
}
