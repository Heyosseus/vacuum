<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Learn;

use Heyosseus\Vacuum\Exceptions\InvalidCurriculum;

/**
 * Every registered lesson, in the order a reader is meant to meet them. The
 * container builds this once from whatever is tagged under
 * VacuumServiceProvider::LESSONS, the same way it builds an Inspection from
 * its tagged rules -- a lesson is declared in exactly one place rather than
 * in a bind, a tag, and a list.
 */
final readonly class Curriculum
{
    /** @param list<Lesson> $lessons */
    public function __construct(private array $lessons)
    {
        $this->validate();
    }

    public function find(string $slug): ?Lesson
    {
        foreach ($this->lessons as $lesson) {
            if ($lesson->slug() === $slug) {
                return $lesson;
            }
        }

        return null;
    }

    /** @return list<Lesson> */
    public function all(): array
    {
        return $this->lessons;
    }

    /**
     * Lessons grouped by tier, tiers in Tier::order(), lessons within a tier in
     * registration order. A tier with no lessons does not appear at all.
     *
     * @return array<string, list<Lesson>> keyed by Tier::label()
     */
    public function byTier(): array
    {
        $byTier = [];

        foreach ($this->lessons as $lesson) {
            $byTier[$lesson->tier()->value][] = $lesson;
        }

        uksort(
            $byTier,
            static fn (string $a, string $b): int => Tier::from($a)->order() <=> Tier::from($b)->order(),
        );

        $labelled = [];

        foreach ($byTier as $tier => $lessons) {
            $labelled[Tier::from($tier)->label()] = $lessons;
        }

        return $labelled;
    }

    /**
     * Lessons as the index page draws them: grouped by tier, then nested under
     * whatever they build on.
     *
     * Nesting happens within a tier only. A lesson may name a prerequisite in
     * another tier -- the Eloquent tier is an on-ramp and is meant to point down
     * into the storage material underneath it -- but a panel cannot indent a
     * lesson under a parent it does not render, so a cross-tier edge is a root
     * here and survives as the "builds on" link on the lesson's own page.
     *
     * @return array<string, list<Node>> keyed by Tier::label()
     */
    public function tree(): array
    {
        $tree = [];

        foreach ($this->byTier() as $label => $lessons) {
            $slugs = array_map(static fn (Lesson $lesson): string => $lesson->slug(), $lessons);

            /** @var array<string, list<Lesson>> $children */
            $children = [];

            foreach ($lessons as $lesson) {
                $after = $lesson->after();
                $parent = $after !== null && in_array($after, $slugs, true) ? $after : '';

                $children[$parent][] = $lesson;
            }

            // A slug can never be the empty string, so it is free to stand for
            // "no parent in this tier" without colliding with a real lesson.
            $tree[$label] = $this->nodes('', $children);
        }

        return $tree;
    }

    /**
     * @param  array<string, list<Lesson>>  $children
     * @return list<Node>
     */
    private function nodes(string $parent, array $children): array
    {
        return array_map(
            fn (Lesson $lesson): Node => new Node($lesson, $this->nodes($lesson->slug(), $children)),
            $children[$parent] ?? [],
        );
    }

    /**
     * A curriculum whose edges do not resolve cannot be drawn, so it is refused
     * at construction rather than halfway down a page.
     */
    private function validate(): void
    {
        $slugs = array_map(static fn (Lesson $lesson): string => $lesson->slug(), $this->lessons);

        foreach ($this->lessons as $lesson) {
            $after = $lesson->after();

            if ($after !== null && ! in_array($after, $slugs, true)) {
                throw InvalidCurriculum::unknownPrerequisite($lesson->slug(), $after);
            }
        }

        foreach ($this->lessons as $lesson) {
            $seen = [];
            $at = $lesson;

            while ($at instanceof Lesson) {
                if (isset($seen[$at->slug()])) {
                    throw InvalidCurriculum::cycle($at->slug());
                }

                $seen[$at->slug()] = true;

                $after = $at->after();
                $at = $after === null ? null : $this->find($after);
            }
        }
    }
}
