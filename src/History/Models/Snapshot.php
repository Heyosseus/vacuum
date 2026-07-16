<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\History\Models;

use Heyosseus\Vacuum\History\Models\Concerns\StoresHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One capture of the advisor: the health it found, and — through its children —
 * the findings and the raw metrics behind them, as they stood at `taken_at`.
 *
 * @property int $id
 * @property string $connection
 * @property \Carbon\CarbonImmutable $taken_at
 * @property int $server_version
 * @property int $health_score
 * @property string $grade
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SnapshotFinding> $findings
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SnapshotMetric> $metrics
 */
final class Snapshot extends Model
{
    use StoresHistory;

    protected $table = 'vacuum_snapshots';

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'taken_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'server_version' => 'integer',
        'health_score' => 'integer',
    ];

    /**
     * @return HasMany<SnapshotFinding, $this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(SnapshotFinding::class);
    }

    /**
     * @return HasMany<SnapshotMetric, $this>
     */
    public function metrics(): HasMany
    {
        return $this->hasMany(SnapshotMetric::class);
    }
}
