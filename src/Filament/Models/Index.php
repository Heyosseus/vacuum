<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Filament\Models;

use Heyosseus\Vacuum\Filament\Models\Concerns\ReadsSystemCatalog;
use Heyosseus\Vacuum\Support\IgnoredSchemas;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * A row of pg_stat_user_indexes, seen through Eloquent so the list sorts by size and
 * by how often anything has read the index, in PostgreSQL rather than in PHP.
 *
 * What makes an index worth judging is not in that view: whether it is unique, whether
 * it backs a primary key, whether the planner is even allowed to use it, and how much
 * disk it costs all come from pg_index and pg_relation_size. The resource's query joins
 * them in and casts the booleans to ints so a Postgres 'f' cannot arrive as a truthy
 * string; the model here only has to name what those aliases mean.
 *
 * @property string $schemaname
 * @property string $relname
 * @property string $indexrelname
 * @property int $idx_scan
 * @property int $index_bytes
 * @property bool $is_unique
 * @property bool $is_primary
 * @property bool $is_valid
 * @property bool $is_constraint_owned
 * @property bool $is_replica_identity
 * @property bool $is_partition_child
 */
final class Index extends Model
{
    use ReadsSystemCatalog;

    protected $table = 'pg_stat_user_indexes';

    protected $primaryKey = 'indexrelid';

    public $incrementing = false;

    public $timestamps = false;

    /** @var array<string, string> */
    protected $casts = [
        'idx_scan' => 'integer',
        'index_bytes' => 'integer',
        'is_unique' => 'boolean',
        'is_primary' => 'boolean',
        'is_valid' => 'boolean',
        'is_constraint_owned' => 'boolean',
        'is_replica_identity' => 'boolean',
        'is_partition_child' => 'boolean',
    ];

    /**
     * The ignored schemas are dropped once, in SQL, so PostgreSQL's own indexes cannot
     * leak into the list.
     */
    #[Override]
    protected static function booted(): void
    {
        self::addGlobalScope('vacuumIgnoredSchemas', static function (Builder $query): void {
            $ignored = app(IgnoredSchemas::class)->all();

            if ($ignored !== []) {
                $query->whereNotIn('pg_stat_user_indexes.schemaname', $ignored);
            }
        });
    }

    /** Nothing has read this index since PostgreSQL last reset its counters. */
    public function neverUsed(): bool
    {
        return $this->idx_scan === 0;
    }

    /**
     * Whether the index is a rule the database enforces rather than a shortcut it
     * offers. A unique or primary index may be read by nothing and still earn its keep
     * on every write; an ordinary one that nothing reads is pure cost.
     *
     * Also true of any index PostgreSQL would refuse to drop -- one a constraint
     * depends on, the replica identity, a partition child -- so this panel and the
     * advisor answer the same question the same way.
     */
    public function constrains(): bool
    {
        return $this->is_primary
            || $this->is_unique
            || $this->is_constraint_owned
            || $this->is_replica_identity
            || $this->is_partition_child;
    }
}
