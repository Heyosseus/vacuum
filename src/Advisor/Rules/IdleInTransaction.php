<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor\Rules;

use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\SessionRule;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Values\Session;
use Illuminate\Contracts\Config\Repository;

/**
 * Finds transactions the application opened and then walked away from.
 *
 * This is the quietest serious problem PostgreSQL has. The session is doing
 * nothing, so nothing looks wrong, while its snapshot holds the xmin horizon
 * still and autovacuum is forbidden from reclaiming any dead tuple newer than it
 * -- in any table in the database, not merely the ones this transaction touched.
 */
final readonly class IdleInTransaction implements SessionRule
{
    /** An hour of nothing is not a slow query, it is a leak. */
    private const int CRITICAL_SECONDS = 3_600;

    public function __construct(private Repository $config) {}

    public function inspect(Session $session): ?Finding
    {
        if (! $session->idleInTransaction()) {
            return null;
        }

        if ($session->stateSeconds < $this->threshold()) {
            return null;
        }

        $minutes = number_format($session->stateSeconds / 60, 1);
        $who = $session->application === '' ? $session->user : $session->application;

        return new Finding(
            rule: 'idle-in-transaction',
            subject: "pid {$session->pid}",
            severity: $session->stateSeconds >= self::CRITICAL_SECONDS ? Severity::Critical : Severity::Warning,
            summary: "{$who} has held a transaction open and done nothing with it for {$minutes} minutes.",
            impact: 'An open transaction pins the xmin horizon, and autovacuum may not reclaim a dead tuple '
                .'newer than the oldest snapshot in the database. So this one idle session stops any table '
                .'from being vacuumed properly, including tables it never touched, and the bloat it causes '
                .'will be blamed on the tables rather than on the connection that is sitting here. It also '
                .'holds every lock its transaction took. The cause is usually application code that opens a '
                .'transaction and then waits on something slow -- an HTTP call, a queue, a person.',
            remediation: "SELECT pg_terminate_backend({$session->pid});",

            // What the session is, what it last ran, and how long its transaction
            // has been open. Read this before you terminate anything.
            query: "SELECT pid, usename, application_name, state, xact_start, state_change, query\n"
                ."FROM pg_stat_activity\n"
                ."WHERE pid = {$session->pid};",
        );
    }

    private function threshold(): int
    {
        $threshold = $this->config->get('vacuum.thresholds.idle_in_transaction_seconds', 300);

        return is_numeric($threshold) ? (int) $threshold : 300;
    }
}
