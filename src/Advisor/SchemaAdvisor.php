<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor;

/**
 * Everything the package believes is wrong with the *shape* of the database.
 *
 * Deliberately not part of the main advisor. Health computes the score from the
 * findings and from nothing else, so that the grade can never disagree with the
 * list beneath it -- and the consequence of that lovely property is that adding
 * rules to the shared advisor silently re-grades every installation that
 * upgrades. Somebody's A becomes a C on a composer update with no breaking API
 * change to point at, and the package's most careful decision becomes the
 * mechanism by which it surprises people.
 *
 * So schema findings are merged separately and scored separately. The two scores
 * are never added and never averaged: one is about a database that is running,
 * the other about a schema that is wrong.
 *
 * It wraps an Advisor rather than reimplementing it, which is what gives it, for
 * free, the thing that matters most -- an inspection that throws becomes a
 * finding rather than a broken command.
 */
final readonly class SchemaAdvisor
{
    public function __construct(private Advisor $advisor) {}

    /**
     * @return list<Finding>
     */
    public function findings(): array
    {
        return $this->advisor->findings();
    }
}
