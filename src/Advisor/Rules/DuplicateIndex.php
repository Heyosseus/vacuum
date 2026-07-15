<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor\Rules;

use Heyosseus\Vacuum\Advisor\DuplicateRule;
use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Support\Bytes;
use Heyosseus\Vacuum\Support\Identifier;
use Heyosseus\Vacuum\Values\IndexDuplicate;

/**
 * Finds indexes that are exact copies of another index on the same table.
 *
 * These are not usually a mistake anybody made in one go. They are what a schema
 * accumulates: an index added in a migration that another migration had already
 * added under a different name, a unique constraint whose index somebody indexed
 * again, an index restored from a dump that a deploy then created afresh.
 *
 * Of everything Vacuum reports, this is the safest to act on. The database is
 * doing the same work twice and cannot get anything back for it.
 */
final readonly class DuplicateIndex implements DuplicateRule
{
    // Every duplicate PostgreSQL hands back is a finding: there is no such thing as
    // an acceptable exact copy of an index, so this rule never declines to speak.
    public function inspect(IndexDuplicate $duplicate): Finding
    {
        return new Finding(
            rule: 'duplicate-index',
            subject: $duplicate->qualifiedName(),
            severity: Severity::Warning,
            summary: "This index is an exact copy of {$duplicate->duplicateOf}: same table, same columns, same "
                .'order. It occupies '.Bytes::human($duplicate->bytes).'.',
            impact: "Every insert, update and delete on {$duplicate->table} maintains both of them, so the write "
                .'cost of the index is paid twice and the benefit is collected once. Both are copied by every '
                .'backup, both take cache the rows could be using, and the planner will only ever choose one.'
                .($duplicate->constrains
                    ? ' This one is unique or a primary key, so a constraint probably depends on it and '
                        .'PostgreSQL will refuse to drop the index on its own: drop the constraint with ALTER '
                        .'TABLE instead, and check first that nothing in your schema references it.'
                    : ''),
            remediation: 'DROP INDEX CONCURRENTLY '.Identifier::qualified($duplicate->schema, $duplicate->name).';',
            evidence: $duplicate->definition,

            // Every index on the table, side by side, so the copy and the original
            // can be read against each other before anything is dropped.
            query: "SELECT indexname, indexdef\n"
                ."FROM pg_indexes\n"
                .'WHERE schemaname = '.Identifier::literal($duplicate->schema)
                .' AND tablename = '.Identifier::literal($duplicate->table)."\n"
                .'ORDER BY indexname;',
            table: $duplicate->schema.'.'.$duplicate->table,
        );
    }
}
