<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor\Rules;

use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Advisor\StatementRule;
use Heyosseus\Vacuum\Values\Statement;
use Illuminate\Contracts\Config\Repository;

/**
 * Finds the shapes of query that cost the most every time they run.
 */
final readonly class SlowStatement implements StatementRule
{
    /** Several times over the line is a different kind of problem from just over it. */
    private const int CRITICAL_MULTIPLE = 5;

    public function __construct(private Repository $config) {}

    public function inspect(Statement $statement): ?Finding
    {
        $threshold = $this->threshold();

        if ($statement->meanMilliseconds < $threshold) {
            return null;
        }

        $mean = number_format($statement->meanMilliseconds);
        $calls = number_format($statement->calls);
        $total = number_format($statement->totalMilliseconds / 1_000, 1);

        return new Finding(
            rule: 'slow-statement',
            subject: "query {$statement->queryId}",
            severity: $statement->meanMilliseconds >= $threshold * self::CRITICAL_MULTIPLE
                ? Severity::Critical
                : Severity::Warning,
            summary: "Averages {$mean} ms across {$calls} calls, {$total} seconds of database time in all.",
            impact: 'The average hides as much as it shows: a query that is quick nine times out of ten and '
                .'terrible on the tenth has the same mean as one that is uniformly mediocre, and only the '
                .'first is worth chasing. Run it through EXPLAIN (ANALYZE, BUFFERS) with parameters that '
                .'match a slow case, and look for a sequential scan where you expected an index.',
            evidence: $statement->sql,

            // The whole row pg_stat_statements holds for this shape, including the
            // spread the mean is hiding: min, max, and the standard deviation.
            query: "SELECT calls, mean_exec_time, min_exec_time, max_exec_time, stddev_exec_time, rows, query\n"
                ."FROM pg_stat_statements\n"
                .'WHERE queryid = '.$statement->queryId.';',
        );
    }

    private function threshold(): float
    {
        $threshold = $this->config->get('vacuum.thresholds.slow_query_milliseconds', 500);

        return is_numeric($threshold) ? (float) $threshold : 500.0;
    }
}
