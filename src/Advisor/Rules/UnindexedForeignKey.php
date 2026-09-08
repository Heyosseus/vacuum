<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor\Rules;

use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\SchemaRule;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Support\Identifier;
use Heyosseus\Vacuum\Values\TableSchema;

/**
 * Finds foreign keys with no index behind them.
 *
 * PostgreSQL indexes a primary key and a unique constraint and creates nothing
 * at all for a foreign key. MySQL does, which is why a schema that was fast
 * there is slow here and why nobody thinks to look: $table->foreignId('customer_id')
 * ->constrained() writes a constraint and no index, and the framework gives no
 * hint that half of what you asked for was not done.
 *
 * The cost lands somewhere nobody is watching. It is not the child's reads that
 * suffer -- it is the parent's writes: every DELETE and every key UPDATE on the
 * parent has to prove no child still references the row, and with no index that
 * proof is a sequential scan of the child table, taken while holding a lock. A
 * table that is fine for two years becomes a table that times out.
 */
final readonly class UnindexedForeignKey implements SchemaRule
{
    /**
     * @return list<Finding>
     */
    public function inspect(TableSchema $table): array
    {
        $findings = [];

        foreach ($table->foreignKeys() as $key) {
            if ($key->indexed) {
                continue;
            }
            if ($key->columns === []) {
                continue;
            }
            $leading = $key->columns[0];
            $columns = implode(', ', $key->columns);
            $quoted = implode(', ', array_map(Identifier::quote(...), $key->columns));

            $findings[] = new Finding(
                rule: 'unindexed-foreign-key',
                subject: $table->qualifiedName().'.'.$leading,
                severity: Severity::Warning,
                summary: "The foreign key {$key->name} on ({$columns}) has no index behind it. PostgreSQL "
                    .'creates one for a primary key and for a unique constraint, and none for this.',
                impact: "Every delete and every key update on {$key->referencedTable} has to prove that no row "
                    ."in {$table->table} still points at it. With no index that proof is a sequential scan of "
                    ."{$table->table}, taken while holding a lock, and it gets slower every time the table "
                    .'grows. Nothing about it shows up where you would look for it, because the cost is paid '
                    .'by the parent and the missing index is on the child.',
                remediation: 'CREATE INDEX CONCURRENTLY ON '
                    .Identifier::qualified($table->schema, $table->table)." ({$quoted});",
                evidence: "FOREIGN KEY ({$columns}) REFERENCES {$key->referencedTable}",
                query: "SELECT indexname, indexdef\n"
                    ."FROM pg_indexes\n"
                    .'WHERE schemaname = '.Identifier::literal($table->schema)
                    .' AND tablename = '.Identifier::literal($table->table)."\n"
                    .'ORDER BY indexname;',
                table: $table->qualifiedName(),
            );
        }

        return $findings;
    }
}
