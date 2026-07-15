<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor\Rules;

use Carbon\CarbonImmutable;
use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Advisor\TableRule;
use Heyosseus\Vacuum\Support\Identifier;
use Heyosseus\Vacuum\Values\TableStatistic;
use Illuminate\Contracts\Config\Repository;

/**
 * Finds tables the planner is reasoning about from statistics that are no longer
 * true.
 *
 * PostgreSQL does not read your tables to plan a query. It reads a sample of them
 * taken at the last ANALYZE — how many rows there are, how many distinct values a
 * column holds, what the common ones are — and decides from that whether to scan
 * an index or the whole table, and whether to hash a join or loop over it. Get the
 * row estimate wrong by two orders of magnitude and it will pick a nested loop for
 * a million rows, confidently, and stay wrong until somebody analyzes the table.
 *
 * Autoanalyze normally does this at ten percent of the table. This rule only
 * complains at twice that, because at ten percent autoanalyze is doing its job and
 * at twenty it is being outrun.
 */
final readonly class StaleStatistics implements TableRule
{
    /** Statistics that describe more rows than the table holds are not stale. They are fiction. */
    private const float CRITICAL_RATIO = 1.0;

    public function __construct(private Repository $config) {}

    public function inspect(TableStatistic $table): ?Finding
    {
        if ($table->liveTuples < $this->minimumRows()) {
            return null;
        }

        $analyzed = $table->lastAnalyzedAt();

        if (! $analyzed instanceof CarbonImmutable) {
            return $this->finding(
                $table,
                Severity::Warning,
                'This table has never been analyzed, and it holds '
                    .number_format($table->liveTuples).' rows. The planner has no statistics for it at all and is '
                    .'working from defaults.',
                evidence: null,
            );
        }

        if ($table->modificationsSinceAnalyze < $this->minimumModifications()) {
            return null;
        }

        $ratio = $table->modificationsSinceAnalyze / $table->liveTuples;

        if ($ratio < $this->ratio()) {
            return null;
        }

        $share = number_format($ratio * 100, 1).'%';

        return $this->finding(
            $table,
            $ratio >= self::CRITICAL_RATIO ? Severity::Critical : Severity::Warning,
            number_format($table->modificationsSinceAnalyze).' rows have been written since this table was last '
                ."analyzed, which is {$share} of the ".number_format($table->liveTuples).' it holds.',
            evidence: 'Last analyzed '.$analyzed->toDateTimeString().'.',
        );
    }

    private function finding(TableStatistic $table, Severity $severity, string $summary, ?string $evidence): Finding
    {
        return new Finding(
            rule: 'stale-statistics',
            subject: $table->qualifiedName(),
            severity: $severity,
            summary: $summary,
            impact: 'The planner chooses between an index scan and a sequential scan, and between hashing a join '
                .'and looping over it, using the row counts it recorded at the last analyze. When those counts are '
                .'wrong it does not hesitate: it picks the plan that would have been right for the table as it used '
                .'to be, and a query that ran in milliseconds runs for minutes with nothing in the logs to say why.',
            remediation: 'ANALYZE '.Identifier::qualified($table->schema, $table->name).';',
            evidence: $evidence,

            // What the planner currently believes about each column of this table.
            // These are the numbers it is choosing plans from.
            query: "SELECT attname, n_distinct, null_frac, correlation\n"
                ."FROM pg_stats\n"
                .'WHERE schemaname = '.Identifier::literal($table->schema)
                .' AND tablename = '.Identifier::literal($table->name)."\n"
                .'ORDER BY attname;',
            table: $table->qualifiedName(),
        );
    }

    private function ratio(): float
    {
        $ratio = $this->config->get('vacuum.thresholds.stale_statistics_ratio', 0.2);

        return is_numeric($ratio) ? (float) $ratio : 0.2;
    }

    private function minimumModifications(): int
    {
        $minimum = $this->config->get('vacuum.thresholds.stale_statistics_minimum', 10_000);

        return is_numeric($minimum) ? (int) $minimum : 10_000;
    }

    /**
     * Nobody plans a query over a thousand rows badly enough for it to matter: at
     * that size PostgreSQL reads the whole table whatever it believes about it.
     */
    private function minimumRows(): int
    {
        $minimum = $this->config->get('vacuum.thresholds.stale_statistics_minimum_rows', 1_000);

        return is_numeric($minimum) ? (int) $minimum : 1_000;
    }
}
