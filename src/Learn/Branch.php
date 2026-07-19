<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Learn;

/**
 * One arm of a fork a lesson resolves: the test, what to do when it holds, and
 * whichever of the reader's own tables turned out to take it.
 *
 * A branch nothing landed on is still rendered. The tree is teaching material
 * first and a verdict second -- a reader whose database happens not to
 * demonstrate the other arm still needs to know the other arm exists, and a
 * fresh install with no statistics at all still gets a complete lesson.
 */
final readonly class Branch
{
    /**
     * @param  string  $condition  The test in plain words: 'fillfactor is still 100'.
     * @param  string  $outcome  What to do when it holds.
     * @param  list<string>  $landed  The reader's own tables that took this branch,
     *                                already formatted. Empty is the normal case and
     *                                renders as nothing rather than as an empty list.
     * @param  string|null  $fix  A statement to show under the branch, or null. Shown
     *                            with the copy affordance and never executed.
     */
    public function __construct(
        public string $condition,
        public string $outcome,
        public array $landed = [],
        public ?string $fix = null,
    ) {}

    public function isTaken(): bool
    {
        return $this->landed !== [];
    }
}
