<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Learn;

/**
 * The fork a lesson resolves, with the reader's own tables sorted onto its arms.
 *
 * A lesson that says "it depends" and stops has failed the reader. This is what
 * it depends on, stated as a question with a branch per answer -- and because
 * the branches carry the tables that took them, the reader is not left to work
 * out which arm they are on.
 */
final readonly class Tree
{
    /** @param list<Branch> $branches */
    public function __construct(
        public string $question,
        public array $branches,
    ) {}
}
