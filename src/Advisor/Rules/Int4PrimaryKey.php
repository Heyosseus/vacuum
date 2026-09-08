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
 * Finds primary keys counted in 32 bits.
 *
 * This is the wraparound story told in a different register. A four-byte integer
 * counts to 2,147,483,647 and then the next insert fails, and like wraparound it
 * gives no warning of its own and does not slow down first: the table is
 * completely healthy at two billion rows and refuses to accept a row at two
 * billion and one.
 *
 * Laravel's $table->increments('id') produced exactly this, and it was the
 * framework's default until 5.8 -- so the schemas carrying it are, by
 * construction, the oldest and busiest ones, which are also the ones nearest the
 * ceiling and the hardest to migrate. Widening the column rewrites the whole
 * table under an ACCESS EXCLUSIVE lock, and every foreign key pointing at it has
 * to be widened in the same breath or it becomes a type mismatch instead. That is
 * a planned migration with a maintenance window, and the reason to say so now is
 * that it only gets more expensive.
 *
 * One table is deliberately exempt: public.migrations. It is written by
 * Laravel's own DatabaseMigrationRepository, which still creates it with
 * $table->increments('id') in every currently supported release, so this rule
 * would otherwise fire on a stock `laravel new` application with no code the
 * application owns to change. A table that gains exactly one row per migration
 * run is never going to approach a ceiling that a busy application table could
 * reach in months, so the finding would be pure noise -- and vacuum:lint's
 * default --fail-on=warning would fail that build on a table nobody can fix.
 */
final readonly class Int4PrimaryKey implements SchemaRule
{
    /** What format_type renders for the integer types narrower than bigint. */
    private const array NARROW = ['integer', 'smallint'];

    /**
     * Laravel's own migration-tracking table, written by
     * DatabaseMigrationRepository::createRepository() with $table->increments('id')
     * and never touched by the application. See the class docblock for why this
     * rule stays silent about it.
     */
    private const string FRAMEWORK_MIGRATIONS = 'migrations';

    /**
     * @return list<Finding>
     */
    public function inspect(TableSchema $table): array
    {
        if ($table->table === self::FRAMEWORK_MIGRATIONS) {
            return [];
        }

        $key = $table->primaryKey();

        if (! $key instanceof Constraint) {
            return [];
        }

        $findings = [];

        foreach ($key->columns as $name) {
            $column = $table->column($name);
            if (! $column instanceof Column) {
                continue;
            }
            if (! in_array($column->type, self::NARROW, true)) {
                continue;
            }

            $ceiling = $column->type === 'smallint' ? '32,767' : '2,147,483,647';

            $findings[] = new Finding(
                rule: 'int4-primary-key',
                subject: $table->qualifiedName().'.'.$name,
                severity: Severity::Warning,
                summary: "The primary key column {$name} is {$column->type}, so it can count to {$ceiling} "
                    .'and no further. The insert after that one fails.',
                impact: 'There is no warning and no gradual slowdown: the table is perfectly healthy right up '
                    .'to the last value and refuses the next row. Widening it later rewrites the whole table '
                    .'under a lock that blocks every reader and writer, and widening every foreign key that '
                    .'points at it in the same migration -- leave one behind and it becomes a type mismatch, '
                    .'which is slow forever and invisible. The cost of this migration only grows.',
                remediation: 'ALTER TABLE '.Identifier::qualified($table->schema, $table->table)
                    .' ALTER COLUMN '.Identifier::quote($name).' TYPE bigint;',
                evidence: "{$name} {$column->type}",

                // How much of the counter has actually been spent. The schema says
                // the ceiling exists; only the sequence says how close it is.
                query: "SELECT last_value, max_value, round(100.0 * last_value / max_value, 2) AS percent_used\n"
                    .'FROM pg_sequences'."\n"
                    .'WHERE schemaname = '.Identifier::literal($table->schema)
                    .' AND sequencename LIKE '.Identifier::literal($table->table.'%').';',
                table: $table->qualifiedName(),
            );
        }

        return $findings;
    }
}
