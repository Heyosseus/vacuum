<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Filament\Models;

use Heyosseus\Vacuum\Filament\Models\Concerns\ReadsSystemCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * A shape of query pg_stat_statements has been watching, seen through Eloquent so the
 * panel can order the whole population by total or mean time in SQL and page through it.
 *
 * Not a query: pg_stat_statements normalises the parameters away, so a row is every
 * SELECT ... WHERE id = $1 the application has ever run, added up as one. That is what
 * makes the totals meaningful and the text unrunnable. The reads of the statistics
 * themselves are kept out of the statistics -- a dashboard reporting the cost of
 * watching the dashboard is a dashboard nobody trusts twice.
 *
 * The view itself does not quite hold that row, though, which is why this model reads a
 * grouped subquery rather than the view directly. pg_stat_statements keys on
 * (userid, dbid, queryid, toplevel): one statement run by the application's role and by
 * a migration's role is two rows sharing a queryid. Against the raw view the primary key
 * below would be a claim the data does not support, and a sorted, paged table built on a
 * non-unique key repeats rows on one page and drops them from another. Grouping first
 * makes queryid mean what the model says it means.
 *
 * @property string $queryid
 * @property string $query
 * @property int $calls
 * @property float $total_exec_time
 * @property float $mean_exec_time
 * @property int $rows
 */
final class Statement extends Model
{
    use ReadsSystemCatalog;

    protected $table = 'pg_stat_statements';

    protected $primaryKey = 'queryid';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /** @var array<string, string> */
    protected $casts = [
        'calls' => 'integer',
        'total_exec_time' => 'float',
        'mean_exec_time' => 'float',
        'rows' => 'integer',
    ];

    /**
     * This database's statements only, never Vacuum's own reading of them, and one
     * record per query shape rather than one per role that ran it.
     *
     * The filters live inside the subquery because after the grouping they could
     * not run at all: dbid and the per-role rows are gone by then, and query has
     * become an aggregate. Aliasing the result back to pg_stat_statements keeps
     * the model, its casts and every column the resource sorts on unchanged --
     * from the outside this is still a table of statements.
     */
    #[Override]
    protected static function booted(): void
    {
        self::addGlobalScope('vacuumStatementsByQueryid', static function (Builder $query): void {
            $aggregated = $query->getQuery()->newQuery()
                ->from('pg_stat_statements')
                ->selectRaw('queryid')
                ->selectRaw('min(query) AS query')
                ->selectRaw('sum(calls) AS calls')
                ->selectRaw('sum(total_exec_time) AS total_exec_time')
                ->selectRaw('sum(total_exec_time) / nullif(sum(calls), 0) AS mean_exec_time')
                ->selectRaw('sum(rows) AS rows')
                ->whereRaw('pg_stat_statements.dbid = (SELECT oid FROM pg_database WHERE datname = current_database())')
                ->whereRaw("pg_stat_statements.query NOT LIKE '%pg_stat_%'")
                ->whereNotNull('pg_stat_statements.queryid')
                ->groupBy('pg_stat_statements.queryid');

            $query->fromSub($aggregated, 'pg_stat_statements');
        });
    }
}
