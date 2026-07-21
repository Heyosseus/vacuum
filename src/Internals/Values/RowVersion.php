<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Internals\Values;

/**
 * One physical version of one row, as PostgreSQL's own system columns see
 * it: where it lives, and which transaction wrote it.
 *
 * A row's ctid changes every time it is updated -- the old version is left
 * behind at its old ctid until vacuumed away, and the new version gets a
 * ctid of its own. Reading every version currently on disk, rather than
 * only the live one a normal SELECT returns, is what makes an update's
 * physical cost visible.
 */
final readonly class RowVersion
{
    /**
     * @param  bool  $untouched  Whether nothing has written xmax on this version at
     *                           all. Deliberately not called "current": a plain
     *                           SELECT can read the system columns but not the
     *                           infomask, and without the infomask there is no way
     *                           to tell a row that was deleted from one that was
     *                           merely locked, or one whose deleting transaction
     *                           aborted. Both of the latter are current and both
     *                           carry an xmax, so this column can honestly report
     *                           that xmax is unset and must not claim more. The
     *                           heap-page panel above, which does read the
     *                           infomask, is where that question gets a real answer.
     */
    public function __construct(
        public string $ctid,
        public int $block,
        public int $offset,
        public string $xmin,
        public string $xmax,
        public bool $untouched,
    ) {}
}
