<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor\Inspections;

use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Inspection;
use Heyosseus\Vacuum\Advisor\SessionRule;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Queries\Sessions;
use Heyosseus\Vacuum\Values\Capabilities;

/**
 * Puts every session rule to every connection to the database.
 *
 * Unlike the cache, a role without pg_read_all_stats still sees something here --
 * its own sessions -- which is worse than seeing nothing, because a half-empty
 * list looks exactly like a quiet database. So the inspection reports what it can
 * and says out loud that it is not the whole picture.
 */
final readonly class SessionInspection implements Inspection
{
    /**
     * @param  iterable<SessionRule>  $rules
     */
    public function __construct(
        private Capabilities $capabilities,
        private Sessions $sessions,
        private iterable $rules,
    ) {}

    /**
     * @return list<Finding>
     */
    public function findings(): array
    {
        $findings = [];

        if (! $this->capabilities->readsAllStatistics) {
            $findings[] = $this->partialSight();
        }

        foreach ($this->sessions->all() as $session) {
            foreach ($this->rules as $rule) {
                $finding = $rule->inspect($session);

                if ($finding instanceof Finding) {
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }

    private function partialSight(): Finding
    {
        return new Finding(
            rule: 'partial-visibility',
            subject: 'database',
            severity: Severity::Unknown,
            summary: 'This role can only see its own sessions, so anything below is part of the picture.',
            impact: 'PostgreSQL hides the query text and state of other roles\' sessions from a role without '
                .'pg_read_all_stats. The transaction blocking your database may be sitting in this view as a '
                .'row of nulls. A half-empty list of sessions reads exactly like a quiet database, which is '
                .'the failure worth knowing about.',
            remediation: 'GRANT pg_read_all_stats TO current_user;',
        );
    }
}
