<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor\Rules;

use Carbon\CarbonImmutable;
use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\IndexRule;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Support\Bytes;
use Heyosseus\Vacuum\Support\Identifier;
use Heyosseus\Vacuum\Values\IndexStatistic;
use Illuminate\Contracts\Config\Repository;

/**
 * Finds large indexes that nothing has ever read.
 *
 * An index nobody reads is not free. Every insert, update and delete on the table
 * writes to it, it is copied by every backup, and it takes cache the rows
 * themselves could have used.
 */
final readonly class UnusedIndex implements IndexRule
{
    public function __construct(private Repository $config) {}

    public function inspect(IndexStatistic $index): ?Finding
    {
        if (! $index->neverUsed()) {
            return null;
        }

        // A unique or primary index may be read by nothing and still be doing its
        // job on every write: it is a rule the database enforces, not a shortcut.
        if ($index->constrains()) {
            return null;
        }

        if ($index->bytes < $this->minimum()) {
            return null;
        }

        return new Finding(
            rule: 'unused-index',
            subject: $index->qualifiedName(),
            severity: Severity::Warning,
            summary: 'No query has used this index '.$this->period($index).'. It occupies '
                .Bytes::human($index->bytes).'.',
            impact: "Every insert, update and delete on {$index->table} maintains this index, every backup "
                .'copies it, and it holds cache the rows themselves could be using. Before you drop it: the '
                .'counters belong to this server alone, so an index untouched here may be serving every read '
                .'on a replica, and an index used by a monthly report looks unused for twenty-nine days.',
            remediation: 'DROP INDEX CONCURRENTLY '.Identifier::qualified($index->schema, $index->name).';',
        );
    }

    /**
     * How long "never" has been. A scan count of zero means nothing without it.
     */
    private function period(IndexStatistic $index): string
    {
        if (! $index->countingSince instanceof CarbonImmutable) {
            return 'for as long as PostgreSQL has been counting';
        }

        return 'since the counters were last reset on '.$index->countingSince->format('j M Y');
    }

    private function minimum(): int
    {
        $minimum = $this->config->get('vacuum.thresholds.unused_index_min_size', 1024 * 1024);

        return is_numeric($minimum) ? (int) $minimum : 1024 * 1024;
    }
}
