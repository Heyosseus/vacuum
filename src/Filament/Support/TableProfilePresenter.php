<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Filament\Support;

use Carbon\CarbonImmutable;
use Heyosseus\Vacuum\Support\Bytes;
use Heyosseus\Vacuum\Values\TableProfile;

/**
 * Turns one table's profile into the strings the drill-down page shows, so the
 * page itself declares only which rows exist and never how a number is spelled.
 *
 * It is the Filament twin of resources/views/table.blade.php: the same facts, the
 * same "never" and "none" and "—" where a ratio has nothing to divide, kept in one
 * place so the two UIs can never quietly disagree about what a table's numbers mean.
 *
 * @phpstan-type Rows array<string, string>
 */
final class TableProfilePresenter
{
    /**
     * @return array<string, string>
     */
    public static function rows(TableProfile $profile): array
    {
        return [
            'rows' => number_format($profile->liveTuples),
            'dead_rows' => number_format($profile->deadTuples).' · '.self::percent($profile->deadTupleRatio()),
            'heap' => Bytes::human($profile->heapBytes),
            'indexes' => Bytes::human($profile->indexBytes),
            'toast' => $profile->toastBytes === 0 ? 'none' : Bytes::human($profile->toastBytes),
            'freeze_age' => number_format($profile->xidAge).' txns',

            'sequential_scans' => number_format($profile->sequentialScans).' · '.self::percent($profile->sequentialShare()).' of reads',
            'index_scans' => number_format($profile->indexScans),
            'rows_read_scanning' => number_format($profile->sequentialTuplesRead),
            'rows_found_by_index' => number_format($profile->indexTuplesFetched),

            'inserts' => number_format($profile->inserts),
            'updates' => number_format($profile->updates),
            'deletes' => number_format($profile->deletes),
            'hot_updates' => number_format($profile->hotUpdates).' · '.self::percent($profile->hotUpdateRatio()),

            'last_vacuum' => self::when($profile->lastVacuumedAt()),
            'last_analyze' => self::when($profile->lastAnalyzedAt()),
            'vacuums_at' => number_format($profile->vacuumsAt()).' dead rows',
            'analyzes_at' => number_format($profile->analyzesAt()).' changed rows',
            'autovacuum' => $profile->tuned ? 'tuned for this table' : 'the server defaults',
        ];
    }

    /**
     * A ratio as a percentage, or a dash when there was nothing to take a share of.
     * A null share is a different fact from zero -- "nothing has read this" is not
     * "no reads were sequential" -- and the page should show the different thing.
     */
    private static function percent(?float $ratio): string
    {
        return $ratio === null ? '—' : number_format($ratio * 100, 1).'%';
    }

    /** When something last happened, in words, or "never" if it never has. */
    private static function when(?CarbonImmutable $moment): string
    {
        return $moment instanceof CarbonImmutable ? $moment->diffForHumans() : 'never';
    }
}
