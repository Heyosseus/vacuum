<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Learn;

/**
 * A lesson and whatever builds on it, for the index page to indent.
 *
 * This exists so the view can render a shape rather than compute one: working
 * out which lesson nests under which is graph work, and a Blade template is the
 * worst place in the codebase to do it.
 */
final readonly class Node
{
    /** @param list<Node> $children */
    public function __construct(
        public Lesson $lesson,
        public array $children = [],
    ) {}
}
