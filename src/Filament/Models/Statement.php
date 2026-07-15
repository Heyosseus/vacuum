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
     * This database's statements only, and never Vacuum's own reading of them.
     */
    #[Override]
    protected static function booted(): void
    {
        self::addGlobalScope('vacuumOwnDatabase', static function (Builder $query): void {
            $query
                ->whereRaw('pg_stat_statements.dbid = (SELECT oid FROM pg_database WHERE datname = current_database())')
                ->whereRaw("pg_stat_statements.query NOT LIKE '%pg_stat_%'");
        });
    }
}
