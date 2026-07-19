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
    public function __construct(
        public string $ctid,
        public int $block,
        public int $offset,
        public string $xmin,
        public string $xmax,
        public bool $isCurrent,
    ) {}
}
