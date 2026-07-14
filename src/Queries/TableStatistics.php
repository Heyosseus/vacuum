<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Queries;

use Carbon\CarbonImmutable;
use Heyosseus\Vacuum\Database\ReadOnlyExecutor;
use Heyosseus\Vacuum\Values\TableStatistic;
use Illuminate\Contracts\Config\Repository;

/**
 * Reads pg_stat_user_tables, the view PostgreSQL keeps of how each table has
 * been written to and when it was last vacuumed or analyzed.
 */
final readonly class TableStatistics
{
    public function __construct(
        private ReadOnlyExecutor $executor,
        private Repository $config,
    ) {}

    /**
     * Every table the configuration does not ignore, the most bloated first.
     *
     * @return list<TableStatistic>
     */
    public function all(): array
    {
        $ignored = $this->ignoredSchemas();

        $sql = <<<'SQL'
            SELECT
                schemaname,
                relname,
                n_live_tup,
                n_dead_tup,
                n_mod_since_analyze,
                last_vacuum,
                last_autovacuum,
                last_analyze,
                last_autoanalyze
            FROM pg_stat_user_tables
            SQL;

        if ($ignored !== []) {
            $placeholders = implode(', ', array_fill(0, count($ignored), '?'));
            $sql .= "\nWHERE schemaname NOT IN ({$placeholders})";
        }

        $sql .= "\nORDER BY n_dead_tup DESC, relname";

        return array_map(
            $this->toStatistic(...),
            $this->executor->select($sql, $ignored),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function toStatistic(array $row): TableStatistic
    {
        return new TableStatistic(
            schema: $this->toText($row['schemaname']),
            name: $this->toText($row['relname']),
            liveTuples: $this->toCount($row['n_live_tup']),
            deadTuples: $this->toCount($row['n_dead_tup']),
            modificationsSinceAnalyze: $this->toCount($row['n_mod_since_analyze']),
            lastVacuum: $this->toTimestamp($row['last_vacuum']),
            lastAutovacuum: $this->toTimestamp($row['last_autovacuum']),
            lastAnalyze: $this->toTimestamp($row['last_analyze']),
            lastAutoanalyze: $this->toTimestamp($row['last_autoanalyze']),
        );
    }

    /**
     * PDO hands back column values as untyped scalars, and these columns are
     * NOT NULL in pg_stat_user_tables, so the fallbacks are a formality.
     */
    private function toText(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function toCount(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * PostgreSQL leaves these null until the table has actually been vacuumed
     * or analyzed, which for most tables in a young database means forever.
     */
    private function toTimestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value);
    }

    /**
     * @return list<string>
     */
    private function ignoredSchemas(): array
    {
        $ignored = $this->config->get('vacuum.ignored_schemas', []);

        if (! is_array($ignored)) {
            return [];
        }

        $schemas = [];

        foreach ($ignored as $schema) {
            if (is_string($schema)) {
                $schemas[] = $schema;
            }
        }

        return $schemas;
    }
}
