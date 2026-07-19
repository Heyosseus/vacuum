<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Queries;

use Heyosseus\Vacuum\Database\ReadOnlyExecutor;
use Heyosseus\Vacuum\Support\Cast;
use Heyosseus\Vacuum\Support\IgnoredSchemas;
use Heyosseus\Vacuum\Support\SqlRepository;
use Heyosseus\Vacuum\Values\TableProfile;

/**
 * Everything PostgreSQL knows about one named table.
 *
 * The name is bound, never interpolated. It arrives from a URL, and a schema and a
 * table name from a URL are exactly the sort of thing that ends up inside a
 * statement if nobody is watching.
 */
final readonly class TableProfiles
{
    private const string STATEMENT = 'table_profile';

    private const string ALL_STATEMENT = 'table_profiles';

    public function __construct(
        private ReadOnlyExecutor $executor,
        private SqlRepository $sql,
        private IgnoredSchemas $ignored,
    ) {}

    /**
     * The table, or null when the connection has no such table. A missing table is
     * not an error: it is a table somebody dropped, or a URL somebody typed.
     */
    public function find(string $schema, string $table): ?TableProfile
    {
        $rows = $this->executor->select($this->sql->get(self::STATEMENT), [$schema, $table]);

        return $rows === [] ? null : $this->toProfile($rows[0]);
    }

    /**
     * Every table PostgreSQL is tracking statistics for, largest first, minus the
     * schemas the application has asked Vacuum to leave alone. A lesson that needs
     * to talk about "the reader's biggest table" or walk every table in turn has no
     * name to bind, so this is the same query with the predicate that names one
     * table swapped for the one that excludes a schema.
     *
     * @return list<TableProfile>
     */
    public function all(): array
    {
        $rows = $this->executor->select(
            $this->sql->get(self::ALL_STATEMENT),
            [implode(',', $this->ignored->all())],
        );

        return array_map($this->toProfile(...), $rows);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function toProfile(array $row): TableProfile
    {
        $options = $this->reloptions(Cast::text($row['reloptions'] ?? null));

        return new TableProfile(
            schema: Cast::text($row['schemaname'] ?? null),
            name: Cast::text($row['relname'] ?? null),

            liveTuples: Cast::integer($row['n_live_tup'] ?? null),
            deadTuples: Cast::integer($row['n_dead_tup'] ?? null),
            modificationsSinceAnalyze: Cast::integer($row['n_mod_since_analyze'] ?? null),
            xidAge: Cast::integer($row['xid_age'] ?? null),
            mxidAge: Cast::integer($row['mxid_age'] ?? null),

            heapBytes: Cast::integer($row['heap_bytes'] ?? null),
            indexBytes: Cast::integer($row['index_bytes'] ?? null),
            toastBytes: Cast::integer($row['toast_bytes'] ?? null),
            totalBytes: Cast::integer($row['total_bytes'] ?? null),

            sequentialScans: Cast::integer($row['seq_scan'] ?? null),
            sequentialTuplesRead: Cast::integer($row['seq_tup_read'] ?? null),
            indexScans: Cast::integer($row['idx_scan'] ?? null),
            indexTuplesFetched: Cast::integer($row['idx_tup_fetch'] ?? null),

            inserts: Cast::integer($row['n_tup_ins'] ?? null),
            updates: Cast::integer($row['n_tup_upd'] ?? null),
            hotUpdates: Cast::integer($row['n_tup_hot_upd'] ?? null),
            deletes: Cast::integer($row['n_tup_del'] ?? null),

            lastVacuum: Cast::timestamp($row['last_vacuum'] ?? null),
            lastAutovacuum: Cast::timestamp($row['last_autovacuum'] ?? null),
            lastAnalyze: Cast::timestamp($row['last_analyze'] ?? null),
            lastAutoanalyze: Cast::timestamp($row['last_autoanalyze'] ?? null),

            // A table's own reloption beats the server's setting, which is the whole
            // reason both are read: the effective number is the one that decides when
            // anything actually happens.
            vacuumScaleFactor: Cast::decimal(
                $options['autovacuum_vacuum_scale_factor'] ?? $row['vacuum_scale_factor'] ?? null,
            ),
            vacuumThreshold: Cast::integer(
                $options['autovacuum_vacuum_threshold'] ?? $row['vacuum_threshold'] ?? null,
            ),
            analyzeScaleFactor: Cast::decimal(
                $options['autovacuum_analyze_scale_factor'] ?? $row['analyze_scale_factor'] ?? null,
            ),
            analyzeThreshold: Cast::integer(
                $options['autovacuum_analyze_threshold'] ?? $row['analyze_threshold'] ?? null,
            ),
            tuned: $this->overridesAutovacuum($options),

            // A fillfactor is a storage parameter, not an autovacuum one, so it is
            // read straight from the already-parsed reloptions rather than through
            // overridesAutovacuum(). Left null when the table never set one, which
            // is the common case: PostgreSQL does not write fillfactor=100 back out
            // just because that happens to be the default.
            fillfactor: isset($options['fillfactor']) ? (int) $options['fillfactor'] : null,
        );
    }

    /**
     * Whether the table sets any autovacuum parameter of its own. A fillfactor is a
     * storage parameter too, and it is not autovacuum being tuned.
     *
     * @param  array<string, string>  $options
     */
    private function overridesAutovacuum(array $options): bool
    {
        foreach (array_keys($options) as $name) {
            if (str_starts_with($name, 'autovacuum_')) {
                return true;
            }
        }

        return false;
    }

    /**
     * PostgreSQL hands back a table's storage parameters as one string of the form
     * fillfactor=90,autovacuum_vacuum_scale_factor=0.01, so it is split back into
     * the settings somebody actually wrote.
     *
     * @return array<string, string>
     */
    private function reloptions(string $reloptions): array
    {
        if ($reloptions === '') {
            return [];
        }

        $options = [];

        foreach (explode(',', $reloptions) as $option) {
            if (str_contains($option, '=')) {
                [$name, $value] = explode('=', $option, 2);

                $options[$name] = $value;
            }
        }

        return $options;
    }
}
