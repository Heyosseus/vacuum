<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor\Rules;

use Heyosseus\Vacuum\Advisor\BloatRule;
use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Support\Bytes;
use Heyosseus\Vacuum\Support\Identifier;
use Heyosseus\Vacuum\Values\BloatEstimate;
use Illuminate\Contracts\Config\Repository;

/**
 * Finds tables whose files are much larger than the rows inside them.
 *
 * A plain VACUUM reclaims dead tuples for reuse but rarely gives the pages back,
 * so a table that once held far more rows than it does now keeps its size. Every
 * sequential scan then reads empty pages, and every backup copies them.
 */
final readonly class TableBloat implements BloatRule
{
    /** More waste than substance is no longer a matter of tuning. */
    private const float CRITICAL_RATIO = 0.5;

    public function __construct(private Repository $config) {}

    public function inspect(BloatEstimate $table): ?Finding
    {
        // Measured in bytes rather than in ratio: the estimate rounds a partly
        // filled page up to a whole one, so every table on earth wastes something.
        if ($table->bloatBytes < $this->threshold()) {
            return null;
        }

        $ratio = $table->bloatRatio();
        $percentage = number_format($ratio * 100, 1).'%';

        return new Finding(
            rule: 'table-bloat',
            subject: $table->qualifiedName(),
            severity: $ratio >= self::CRITICAL_RATIO ? Severity::Critical : Severity::Warning,
            summary: "This table is holding {$percentage} more space than its rows need: "
                .Bytes::human($table->bloatBytes).' wasted of '
                .Bytes::human($table->realBytes).'.',
            impact: 'Every sequential scan reads the empty pages, every backup copies them, and the cache '
                .'holds them instead of rows. Reclaiming the space means rewriting the table: VACUUM FULL '
                .'does it, but it takes an ACCESS EXCLUSIVE lock, which stops reads as well as writes for '
                .'the whole rewrite. On a table anybody is using, pg_repack does the same job without the '
                .'lock, and is what you want in production.',
            remediation: 'VACUUM FULL '.Identifier::qualified($table->schema, $table->name).';',
            query: 'SELECT pg_size_pretty(pg_table_size('.Identifier::literal($table->qualifiedName())."::regclass)) AS table_size,\n"
                .'       pg_size_pretty(pg_indexes_size('.Identifier::literal($table->qualifiedName())."::regclass)) AS index_size,\n"
                ."       n_live_tup, n_dead_tup, last_autovacuum\n"
                ."FROM pg_stat_user_tables\n"
                .'WHERE schemaname = '.Identifier::literal($table->schema)
                .' AND relname = '.Identifier::literal($table->name).';',
            table: $table->qualifiedName(),
        );
    }

    private function threshold(): int
    {
        $threshold = $this->config->get('vacuum.thresholds.bloat_bytes', 100 * 1024 * 1024);

        return is_numeric($threshold) ? (int) $threshold : 100 * 1024 * 1024;
    }
}
