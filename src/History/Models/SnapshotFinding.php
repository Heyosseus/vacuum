<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\History\Models;

use Heyosseus\Vacuum\History\Models\Concerns\StoresHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A finding as it stood in one snapshot. Its identity for diffing one snapshot
 * against the last is (rule, subject): that pair is what says whether a finding is
 * new, unchanged, or has cleared.
 *
 * @property int $id
 * @property int $snapshot_id
 * @property string $rule
 * @property string $subject
 * @property string $severity
 * @property string|null $table_name
 * @property string $summary
 * @property float|null $value
 * @property-read Snapshot $snapshot
 */
final class SnapshotFinding extends Model
{
    use StoresHistory;

    protected $table = 'vacuum_snapshot_findings';

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'snapshot_id' => 'integer',
        'value' => 'float',
    ];

    /**
     * @return BelongsTo<Snapshot, $this>
     */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(Snapshot::class);
    }
}
