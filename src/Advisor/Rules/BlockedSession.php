<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor\Rules;

use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\SessionRule;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Values\Session;
use Illuminate\Contracts\Config\Repository;

/**
 * Finds sessions stuck waiting for a lock somebody else is holding.
 */
final readonly class BlockedSession implements SessionRule
{
    public function __construct(private Repository $config) {}

    public function inspect(Session $session): ?Finding
    {
        if (! $session->blocked()) {
            return null;
        }

        $blockers = implode(', ', $session->blockedBy);
        $seconds = $session->stateSeconds;

        return new Finding(
            rule: 'blocked-session',
            subject: "pid {$session->pid}",
            severity: $seconds >= $this->threshold() ? Severity::Critical : Severity::Warning,
            summary: "This session has waited {$seconds} seconds for a lock held by {$blockers}.",
            impact: 'A blocked session holds its own locks while it waits, so a queue forms behind it and the '
                .'database looks slower than any single query can explain. Cancel the blocker rather than the '
                .'session that is waiting: pg_cancel_backend stops the statement and leaves the connection '
                .'alive to roll back, where pg_terminate_backend takes the whole connection down.',
            remediation: 'SELECT pg_cancel_backend('.($session->blockedBy[0] ?? 0).');',

            // The whole chain, waiter and blockers together, so you can see what the
            // blocker is doing before you cancel it.
            query: "SELECT pid, state, wait_event_type, wait_event, xact_start, query\n"
                ."FROM pg_stat_activity\n"
                ."WHERE pid IN ({$session->pid}, {$blockers});",
        );
    }

    private function threshold(): int
    {
        $threshold = $this->config->get('vacuum.thresholds.long_running_query_seconds', 60);

        return is_numeric($threshold) ? (int) $threshold : 60;
    }
}
