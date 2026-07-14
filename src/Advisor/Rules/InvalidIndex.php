<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor\Rules;

use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\IndexRule;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Support\Bytes;
use Heyosseus\Vacuum\Support\Identifier;
use Heyosseus\Vacuum\Values\IndexStatistic;

/**
 * Finds indexes the planner is not allowed to use.
 *
 * CREATE INDEX CONCURRENTLY builds an index in two passes so it never locks the
 * table against writes. If the second pass fails — a deadlock, a unique violation
 * in the data, a cancelled statement, a connection dropped by a deploy — the index
 * stays behind marked invalid. It is the worst of both worlds: every write to the
 * table maintains it, and no query is permitted to read it.
 *
 * The failure is quiet. The CREATE INDEX statement reports an error, into a
 * migration log nobody re-reads, and the application carries on paying for the
 * index forever.
 */
final readonly class InvalidIndex implements IndexRule
{
    public function inspect(IndexStatistic $index): ?Finding
    {
        if ($index->valid) {
            return null;
        }

        return new Finding(
            rule: 'invalid-index',
            subject: $index->qualifiedName(),
            severity: Severity::Warning,
            summary: 'This index is marked invalid, so the planner will not use it. It occupies '
                .Bytes::human($index->bytes).'.',
            impact: "It is paid for and never delivered: every insert, update and delete on {$index->table} "
                .'maintains it, it is copied by every backup, and no query can use it. This is normally what a '
                .'failed CREATE INDEX CONCURRENTLY leaves behind. One caveat before you drop it: an index being '
                .'built by a CREATE INDEX CONCURRENTLY running right now is also marked invalid until it '
                .'finishes, and looks exactly like this. Check that nobody is building it before you drop it.',
            remediation: 'DROP INDEX CONCURRENTLY '.Identifier::qualified($index->schema, $index->name).';',
        );
    }
}
