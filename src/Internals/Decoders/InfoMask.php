<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Internals\Decoders;

/**
 * Turns a heap tuple's t_infomask and t_infomask2 bitmasks into readable
 * state.
 *
 * The bit values below are verbatim from PostgreSQL's
 * src/include/access/htup_details.h. They are not derived from memory and
 * must not be "corrected": a misread bit teaches a reader something false
 * about how their own database works.
 */
final class InfoMask
{
    private const int HEAP_HASNULL = 0x0001;

    private const int HEAP_HASVARWIDTH = 0x0002;

    private const int HEAP_HASEXTERNAL = 0x0004;

    private const int HEAP_HASOID_OLD = 0x0008;

    private const int HEAP_XMAX_KEYSHR_LOCK = 0x0010;

    private const int HEAP_COMBOCID = 0x0020;

    private const int HEAP_XMAX_EXCL_LOCK = 0x0040;

    private const int HEAP_XMAX_LOCK_ONLY = 0x0080;

    private const int HEAP_XMIN_COMMITTED = 0x0100;

    private const int HEAP_XMIN_INVALID = 0x0200;

    private const int HEAP_XMAX_COMMITTED = 0x0400;

    private const int HEAP_XMAX_INVALID = 0x0800;

    private const int HEAP_XMAX_IS_MULTI = 0x1000;

    private const int HEAP_UPDATED = 0x2000;

    private const int HEAP_MOVED_OFF = 0x4000;

    private const int HEAP_MOVED_IN = 0x8000;

    /**
     * HEAP_XMIN_COMMITTED | HEAP_XMIN_INVALID -- a combination that would
     * otherwise be nonsense (committed *and* invalid), which is exactly why
     * it was chosen to mean frozen. Must be tested before xminCommitted(),
     * or a frozen tuple reads as merely committed.
     */
    private const HEAP_XMIN_FROZEN = self::HEAP_XMIN_COMMITTED | self::HEAP_XMIN_INVALID;

    private const int HEAP_NATTS_MASK = 0x07FF;

    private const int HEAP_KEYS_UPDATED = 0x2000;

    private const int HEAP_HOT_UPDATED = 0x4000;

    private const int HEAP_ONLY_TUPLE = 0x8000;

    /**
     * Human-readable names for every set flag that is worth showing a
     * modern reader. The legacy HEAP_MOVED_* bits are omitted here -- they
     * only appear on databases upgraded from before 9.0 and would confuse
     * everyone else -- but they are never silently hidden: movedByVacuumFull()
     * exposes them separately.
     *
     * @return list<string>
     */
    public static function describe(int $infomask, int $infomask2): array
    {
        $flags = [];

        if (($infomask & self::HEAP_HASNULL) !== 0) {
            $flags[] = 'has nulls';
        }

        if (($infomask & self::HEAP_HASVARWIDTH) !== 0) {
            $flags[] = 'has variable-width attributes';
        }

        if (($infomask & self::HEAP_HASEXTERNAL) !== 0) {
            $flags[] = 'has external attributes';
        }

        if (($infomask & self::HEAP_HASOID_OLD) !== 0) {
            $flags[] = 'has object id';
        }

        if (($infomask & self::HEAP_XMAX_KEYSHR_LOCK) !== 0) {
            $flags[] = 'xmax is a key-shared locker';
        }

        if (($infomask & self::HEAP_COMBOCID) !== 0) {
            $flags[] = 'combo cid';
        }

        if (($infomask & self::HEAP_XMAX_EXCL_LOCK) !== 0) {
            $flags[] = 'xmax is an exclusive locker';
        }

        if (self::xmaxLockOnly($infomask)) {
            $flags[] = 'locked only';
        }

        if (self::xminFrozen($infomask)) {
            $flags[] = 'xmin frozen';
        } elseif (self::xminCommitted($infomask)) {
            $flags[] = 'xmin committed';
        }

        if (self::xmaxCommitted($infomask)) {
            $flags[] = 'xmax committed';
        }

        if (self::xmaxInvalid($infomask)) {
            $flags[] = 'xmax invalid';
        }

        if (self::xmaxIsMulti($infomask)) {
            $flags[] = 'xmax is a multixact';
        }

        if (($infomask & self::HEAP_UPDATED) !== 0) {
            $flags[] = 'updated row version';
        }

        if (($infomask2 & self::HEAP_KEYS_UPDATED) !== 0) {
            $flags[] = 'key columns updated';
        }

        if (self::hotUpdated($infomask2)) {
            $flags[] = 'HOT updated';
        }

        if (self::heapOnlyTuple($infomask2)) {
            $flags[] = 'heap-only tuple';
        }

        return $flags;
    }

    /**
     * Whether t_xmin's transaction is known committed. Checked after
     * xminFrozen() by every caller in this class: HEAP_XMIN_FROZEN sets this
     * bit too, and a frozen tuple is not merely committed.
     */
    public static function xminCommitted(int $infomask): bool
    {
        return ($infomask & self::HEAP_XMIN_COMMITTED) !== 0;
    }

    /**
     * Whether the tuple has been frozen -- both HEAP_XMIN_COMMITTED and
     * HEAP_XMIN_INVALID set together, a pair that means nothing on its own.
     */
    public static function xminFrozen(int $infomask): bool
    {
        return ($infomask & self::HEAP_XMIN_FROZEN) === self::HEAP_XMIN_FROZEN;
    }

    public static function xmaxCommitted(int $infomask): bool
    {
        return ($infomask & self::HEAP_XMAX_COMMITTED) !== 0;
    }

    public static function xmaxInvalid(int $infomask): bool
    {
        return ($infomask & self::HEAP_XMAX_INVALID) !== 0;
    }

    public static function xmaxIsMulti(int $infomask): bool
    {
        return ($infomask & self::HEAP_XMAX_IS_MULTI) !== 0;
    }

    /**
     * Whether xmax, if valid at all, only records a locker rather than a
     * deleter or updater. xmax is set on both a locked row and a deleted
     * one; this bit is the only thing that tells them apart.
     */
    public static function xmaxLockOnly(int $infomask): bool
    {
        return ($infomask & self::HEAP_XMAX_LOCK_ONLY) !== 0;
    }

    public static function hotUpdated(int $infomask2): bool
    {
        return ($infomask2 & self::HEAP_HOT_UPDATED) !== 0;
    }

    public static function heapOnlyTuple(int $infomask2): bool
    {
        return ($infomask2 & self::HEAP_ONLY_TUPLE) !== 0;
    }

    /**
     * The number of attributes, packed into the low eleven bits of
     * t_infomask2.
     */
    public static function attributeCount(int $infomask2): int
    {
        return $infomask2 & self::HEAP_NATTS_MASK;
    }

    /**
     * Whether a pre-9.0 VACUUM FULL moved this tuple. Deliberately kept out
     * of describe() because it only means something on a database old
     * enough to have been upgraded across that boundary, but exposed here
     * so nothing about the raw bits is hidden from a caller who asks.
     */
    public static function movedByVacuumFull(int $infomask): bool
    {
        return ($infomask & (self::HEAP_MOVED_OFF | self::HEAP_MOVED_IN)) !== 0;
    }
}
