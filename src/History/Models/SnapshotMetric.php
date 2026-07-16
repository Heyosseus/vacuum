<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\History\Models;

use Heyosseus\Vacuum\History\MetricKind;
use Heyosseus\Vacuum\History\Models\Concerns\StoresHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One measurement in one snapshot: what (`kind`), of what (`object`), and how much
 * (`value`, with `value2` for the second half of a paired counter). Read across
 * snapshots, a kind-and-object's rows are the time series a trend is drawn from.
 *
 * @property int $id
 * @property int $snapshot_id
 * @property MetricKind $kind
 * @property string $object
 * @property float|null $value
 * @property float|null $value2
 * @property-read Snapshot $snapshot
 */
final class SnapshotMetric extends Model
{
    use StoresHistory;

    protected $table = 'vacuum_snapshot_metrics';

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'snapshot_id' => 'integer',
        'kind' => MetricKind::class,
        'value' => 'float',
        'value2' => 'float',
    ];

    /**
     * @return BelongsTo<Snapshot, $this>
     */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(Snapshot::class);
    }
}
