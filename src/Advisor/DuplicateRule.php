<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor;

use Heyosseus\Vacuum\Values\IndexDuplicate;

/**
 * A rule that judges an index against the one it copies.
 *
 * Separate from IndexRule because the subject is different: an IndexRule is handed
 * one index and can only reason about that index. Whether an index is a duplicate
 * is a question about a pair, and PostgreSQL has already answered it by the time
 * this rule sees anything.
 */
interface DuplicateRule
{
    public function inspect(IndexDuplicate $duplicate): ?Finding;
}
