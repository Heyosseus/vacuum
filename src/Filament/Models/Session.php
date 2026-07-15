<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Filament\Models;

use Heyosseus\Vacuum\Filament\Models\Concerns\ReadsSystemCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * A backend connected to this database, seen through pg_stat_activity, so the panel can
 * sort by how long a transaction has been open and filter to the ones worth worrying
 * about -- all in SQL, live, over the connections that exist at the moment it asks.
 *
 * The ages are PostgreSQL's arithmetic, not PHP's: the application server and the
 * database server need not agree about the clock, and a transaction's age is not a
 * number to be casual about. The resource's query computes them and the list of blocking
 * pids; the model only names them and reads a state or two.
 *
 * @property int $pid
 * @property string $usename
 * @property string $application_name
 * @property string $state
 * @property string $query
 * @property int $transaction_seconds
 * @property int $state_seconds
 * @property string $blocked_by
 */
final class Session extends Model
{
    use ReadsSystemCatalog;

    protected $table = 'pg_stat_activity';

    protected $primaryKey = 'pid';

    public $incrementing = false;

    public $timestamps = false;

    /** @var array<string, string> */
    protected $casts = [
        'pid' => 'integer',
        'transaction_seconds' => 'integer',
        'state_seconds' => 'integer',
    ];

    /**
     * Only the connections a person or an application opened, on this database. The
     * autovacuum launcher, the walwriter and the checkpointer are the server keeping
     * itself alive, and nobody opens the panel to police them.
     */
    #[Override]
    protected static function booted(): void
    {
        self::addGlobalScope('vacuumClientBackends', static function (Builder $query): void {
            $query
                ->whereRaw("pg_stat_activity.backend_type = 'client backend'")
                ->whereRaw('pg_stat_activity.datname = current_database()');
        });
    }

    public function active(): bool
    {
        return $this->state === 'active';
    }

    /**
     * A transaction the application opened and then walked away from. The aborted
     * flavour counts: a broken transaction holds its snapshot exactly as firmly as a
     * working one.
     */
    public function idleInTransaction(): bool
    {
        return in_array($this->state, ['idle in transaction', 'idle in transaction (aborted)'], true);
    }

    public function blocked(): bool
    {
        return $this->blocked_by !== '';
    }
}
