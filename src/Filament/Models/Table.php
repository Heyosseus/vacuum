<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Filament\Models;

use Heyosseus\Vacuum\Advisor\Advisor;
use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Filament\Models\Concerns\ReadsSystemCatalog;
use Heyosseus\Vacuum\Queries\IndexStatistics;
use Heyosseus\Vacuum\Queries\TableProfiles;
use Heyosseus\Vacuum\Support\IgnoredSchemas;
use Heyosseus\Vacuum\Values\IndexStatistic;
use Heyosseus\Vacuum\Values\TableProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Override;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A row of pg_stat_user_tables, seen through Eloquent so Filament's own sorting,
 * searching, filtering and pagination push down to PostgreSQL instead of being
 * faked in PHP over a list of value objects.
 *
 * It is deliberately a window and not a door. Vacuum's whole safety claim is that
 * it never writes to the inspected database, so save() and delete() throw rather
 * than reach the connection: the model can read the catalog and nothing more. The
 * connection it reads is Vacuum's own, and a global scope drops the schemas no
 * panel reports on, so the ignored-schema rule is enforced once, in SQL.
 *
 * The list needs only what pg_stat_user_tables holds. The rich per-table picture --
 * the four sizes, the autovacuum thresholds, the findings -- is not in this view,
 * so the drill-down resolves it lazily through the same queries the Blade page uses
 * and memoises it, keeping the second door and the first pointed at one source.
 *
 * @property string $schemaname
 * @property string $relname
 * @property int $n_live_tup
 * @property int $n_dead_tup
 * @property int $seq_scan
 * @property int $idx_scan
 * @property int|null $total_bytes
 */
final class Table extends Model
{
    use ReadsSystemCatalog;

    protected $table = 'pg_stat_user_tables';

    protected $primaryKey = 'relid';

    public $incrementing = false;

    public $timestamps = false;

    /** The whole rich profile of this table, resolved once and remembered. */
    private ?TableProfile $profile = null;

    /** @var list<Finding>|null */
    private ?array $findings = null;

    /** @var list<IndexStatistic>|null */
    private ?array $indexes = null;

    /**
     * Every query the model builds is filtered to the schemas a panel may report
     * on, in SQL, so no catalog table can leak into a list or a URL.
     */
    #[Override]
    protected static function booted(): void
    {
        self::addGlobalScope('vacuumIgnoredSchemas', static function (Builder $query): void {
            $ignored = app(IgnoredSchemas::class)->all();

            if ($ignored !== []) {
                $query->whereNotIn('pg_stat_user_tables.schemaname', $ignored);
            }
        });
    }

    /** The schema-qualified name is what a URL points at; relid means nothing to a reader. */
    #[Override]
    public function getRouteKey(): string
    {
        return $this->schemaname.'.'.$this->relname;
    }

    /**
     * Resolve public.orders back to the one row it names. A key with no dot, or a
     * name nothing matches, resolves to nothing and the page is a 404 -- which is
     * the right answer for a table somebody dropped or a URL somebody typed.
     *
     * @param  Builder<Table>  $query
     * @return Builder<Table>
     */
    #[Override]
    public function resolveRouteBindingQuery($query, $value, $field = null): Builder
    {
        $key = is_scalar($value) ? (string) $value : '';

        [$schema, $name] = array_pad(explode('.', $key, 2), 2, null);

        // Raw predicates rather than where(): pg_stat_user_tables is a system view,
        // so static analysis has no column list for it to check a column name against.
        return $query
            ->whereRaw('pg_stat_user_tables.schemaname = ?', [$schema])
            ->whereRaw('pg_stat_user_tables.relname = ?', [$name]);
    }

    /** The share of this table's rows that are dead, for the list's badge. */
    public function deadTupleRatio(): float
    {
        $total = $this->n_live_tup + $this->n_dead_tup;

        return $total === 0 ? 0.0 : $this->n_dead_tup / $total;
    }

    /**
     * The share of reads that scanned the whole table rather than looking a row up.
     * Null when nothing has read the table at all, which is a different fact from "no
     * scans" and the list says the different thing rather than paint a misleading zero.
     */
    public function sequentialShare(): ?float
    {
        $reads = $this->seq_scan + $this->idx_scan;

        return $reads === 0 ? null : $this->seq_scan / $reads;
    }

    /** The full profile behind the drill-down, resolved once through the same query the Blade page uses. */
    public function profile(): TableProfile
    {
        $profile = $this->profile ??= app(TableProfiles::class)->find($this->schemaname, $this->relname);

        if (! $profile instanceof TableProfile) {
            // The row existed when Filament bound it and is gone now: dropped between
            // the click and the query. A 404 is the honest answer, not a stack trace.
            throw new NotFoundHttpException("There is no table called {$this->schemaname}.{$this->relname} on this connection."); // @codeCoverageIgnore
        }

        return $profile;
    }

    /**
     * What the advisor already said, narrowed to this table. The page judges
     * nothing of its own: a second opinion that disagreed would be a bug.
     *
     * @return list<Finding>
     */
    public function tableFindings(): array
    {
        $qualified = $this->schemaname.'.'.$this->relname;

        return $this->findings ??= array_values(array_filter(
            app(Advisor::class)->findings(),
            static fn (Finding $finding): bool => $finding->table === $qualified,
        ));
    }

    /**
     * Every index on this table, including the ones nothing reads, because an
     * index is most of what a table costs.
     *
     * @return list<IndexStatistic>
     */
    public function tableIndexes(): array
    {
        return $this->indexes ??= array_values(array_filter(
            app(IndexStatistics::class)->all(),
            fn (IndexStatistic $index): bool => $index->schema === $this->schemaname && $index->table === $this->relname,
        ));
    }
}
