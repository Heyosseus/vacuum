<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Learn;

/**
 * One lesson: a piece of PostgreSQL prose that proves itself against the
 * reader's own tables rather than a made-up example. The prose itself lives
 * in a Blade partial named after the slug; a lesson class supplies only the
 * three things a partial cannot: where it sits, what it observed, and what a
 * reader can go run themselves.
 */
interface Lesson
{
    /**
     * The URL segment and the partial's name. Stable once published: a
     * reader may have this bookmarked.
     */
    public function slug(): string;

    public function title(): string;

    public function tier(): Tier;

    /** One line for the index page. Not a summary — a reason to click. */
    public function hook(): string;

    /**
     * The slug this lesson builds on, or null when it is an entry point. The
     * curriculum tree is derived from these edges rather than declared a second
     * time, so a lesson that moves takes its position with it.
     */
    public function after(): ?string;

    /**
     * The fork this lesson resolves, or null when it has none. A lesson that
     * says "it depends" and stops has failed; this is what it depends on.
     */
    public function tree(): ?Tree;

    public function observe(): Observation;

    /** The SELECT for band three, or null when the lesson has nothing to run. */
    public function tryIt(): ?string;
}
