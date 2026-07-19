<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Learn\Lessons;

use Heyosseus\Vacuum\Learn\Branch;
use Heyosseus\Vacuum\Learn\Lesson;
use Heyosseus\Vacuum\Learn\Observation;
use Heyosseus\Vacuum\Learn\Tier;
use Heyosseus\Vacuum\Learn\Tree;
use Heyosseus\Vacuum\Queries\ServerSettings;
use Heyosseus\Vacuum\Queries\Sessions;
use Heyosseus\Vacuum\Values\Session;
use Heyosseus\Vacuum\Values\Settings;

/**
 * Shows a reader whether anything is currently holding a transaction open on
 * their own database, and what that costs while it does.
 *
 * `DB::transaction(function () { ... })` opens exactly one transaction and
 * holds it until the closure returns, no matter what the closure does in
 * between. PostgreSQL cannot remove a dead row version until it is certain no
 * open transaction could still need to see it, so one connection sitting
 * idle inside a transaction stops vacuum from cleaning any table in the
 * database -- not merely the tables that transaction touched. This lesson is
 * a live snapshot of pg_stat_activity, not a history: it can only say what
 * is true right now.
 */
final readonly class TransactionsAndLocks implements Lesson
{
    /**
     * The point at which "idle in transaction" stops being the normal gap
     * between two statements and starts being a connection the application
     * forgot about. Matches the default `vacuum.thresholds.idle_in_transaction_seconds`
     * used by the {@see \Heyosseus\Vacuum\Advisor\Rules\IdleInTransaction} advisor
     * rule, so the lesson and the rule that fires on the reader's own dashboard
     * agree on the same number. There is no threshold PostgreSQL hands back for
     * this -- it is a judgement call about how long "in between statements"
     * plausibly runs, not a boundary the server draws itself.
     */
    private const int IDLE_THRESHOLD_SECONDS = 300;

    /** Enough open transactions to show a pattern without turning the page into a census. */
    private const int ROWS = 10;

    /** Characters of a query text to show before truncating it. */
    private const int QUERY_PREVIEW = 80;

    public function __construct(
        private Sessions $sessions,
        private ServerSettings $settings,
    ) {}

    public function slug(): string
    {
        return 'transactions-and-locks';
    }

    public function title(): string
    {
        return 'The transaction you left open';
    }

    public function tier(): Tier
    {
        return Tier::Eloquent;
    }

    public function hook(): string
    {
        return 'One slow call inside DB::transaction() can stop vacuum working anywhere in the database.';
    }

    public function after(): ?string
    {
        return null;
    }

    public function tree(): Tree
    {
        return $this->fork($this->sessions->all(), $this->settings->read());
    }

    /**
     * The judgement, separated from the fetch so it can be exercised against
     * sessions and settings that were built rather than queried.
     *
     * Public deliberately, for the same reason as {@see Fillfactor::fork()}:
     * Sessions and ServerSettings are both final readonly classes wrapping a
     * read-only executor and cannot be mocked, and the one thing this fork
     * must get right -- that an idle transaction, an unset timeout, and a
     * healthy configuration are three different outcomes -- is precisely
     * what a live database cannot be relied on to demonstrate on demand.
     * Leaving a transaction open for the length of a test run just to
     * exercise this method would also be the exact mistake the lesson warns
     * against, so built Session values stand in for it entirely.
     *
     * @param  list<Session>  $sessions
     */
    public function fork(array $sessions, Settings $settings): Tree
    {
        $idleOverThreshold = array_values(array_filter(
            $sessions,
            static fn (Session $s): bool => $s->idleInTransaction() && $s->transactionSeconds >= self::IDLE_THRESHOLD_SECONDS,
        ));

        usort(
            $idleOverThreshold,
            static fn (Session $a, Session $b): int => $b->transactionSeconds <=> $a->transactionSeconds,
        );

        $anyIdle = array_values(array_filter($sessions, static fn (Session $s): bool => $s->idleInTransaction()));

        $timeout = $settings->integer('idle_in_transaction_session_timeout');
        $timeoutMissing = $timeout === null || $timeout === 0;

        $timeoutUnset = $anyIdle === [] && $timeoutMissing;
        $timeoutSet = $anyIdle === [] && ! $timeoutMissing;

        return new Tree('Is anything holding a transaction open right now?', [
            new Branch(
                condition: 'A session has been idle in transaction for at least '
                    .number_format(self::IDLE_THRESHOLD_SECONDS / 60, 0).' minutes.',
                outcome: 'It is pinning vacuum\'s horizon for the whole database, not just the tables it '
                    .'touched. Find the job or request that opened it and move the slow work -- an HTTP call, '
                    .'a queue dispatch, a file upload -- outside the DB::transaction() closure. If it has to be '
                    .'stopped right now, SELECT pg_terminate_backend(pid) will end the connection, but that is a '
                    .'last resort, not the fix: the same code will do it again tomorrow.',
                landed: array_map($this->describeSession(...), $idleOverThreshold),
            ),
            new Branch(
                condition: 'Nothing is idle in transaction, and idle_in_transaction_session_timeout is unset '
                    .'or zero.',
                outcome: 'Nothing is wrong right now. But there is no backstop for the day something is: set '
                    .'the timeout before you need it, so a forgotten transaction is cut off automatically '
                    .'instead of sitting there until someone notices.',
                landed: $timeoutUnset ? ['idle_in_transaction_session_timeout is unset'] : [],
                fix: $timeoutUnset ? "alter system set idle_in_transaction_session_timeout = '60s';" : null,
            ),
            new Branch(
                condition: 'Nothing is idle in transaction, and idle_in_transaction_session_timeout is already '
                    .'set.',
                outcome: 'This is the good configuration: an idle transaction here gets cut off automatically '
                    .'at '.$this->timeoutDescription($settings).' rather than left to pin the horizon '
                    .'indefinitely. Nothing to do here.',
                landed: $timeoutSet ? ['idle_in_transaction_session_timeout = '.$this->timeoutDescription($settings)] : [],
            ),
        ]);
    }

    public function observe(): Observation
    {
        return $this->report($this->sessions->all());
    }

    /**
     * The judgement, separated from the fetch so it can be exercised against
     * sessions that were built rather than queried.
     *
     * Public deliberately, for the same reason as {@see fork()}: Sessions is a
     * final readonly class wrapping a read-only executor and cannot be mocked,
     * and the branch that matters here -- a transaction actually being held
     * open, idle or active, long enough to say something about it -- is
     * precisely what a healthy test database will never show on its own.
     *
     * @param  list<Session>  $sessions
     */
    public function report(array $sessions): Observation
    {
        $inTransaction = array_values(array_filter(
            $sessions,
            static fn (Session $s): bool => $s->transactionSeconds > 0,
        ));

        if ($inTransaction === []) {
            return new Observation(
                headline: 'No session on this database is currently holding a transaction open.',
                note: 'This is a live snapshot of pg_stat_activity, not a history -- it can only show what is '
                    .'happening at the instant the page loaded, not every transaction that opened and closed '
                    .'since. A clean result here now says nothing about five minutes ago or five minutes from '
                    .'now, only about this moment, which is the normal and healthy thing to see.',
            );
        }

        $longest = $inTransaction[0];
        $minutes = number_format($longest->transactionSeconds / 60, 1);
        $who = $longest->application === '' ? $longest->user : $longest->application;

        return new Observation(
            headline: $longest->idleInTransaction()
                ? "`{$who}` (pid {$longest->pid}) has been idle inside an open transaction for {$minutes} "
                    .'minute(s). That transaction pins vacuum\'s horizon for every table in this database, '
                    .'not only the ones it touched.'
                : "`{$who}` (pid {$longest->pid}) has an active transaction open for {$minutes} minute(s). "
                    .'It is still working, but it is holding the same horizon open while it does.',
            columns: ['pid', 'state', 'duration', 'query'],
            rows: array_map($this->toRow(...), array_slice($inTransaction, 0, self::ROWS)),
        );
    }

    public function tryIt(): string
    {
        return "select pid, state, xact_start, now() - xact_start as duration\n"
            ."from pg_stat_activity\n"
            .'where xact_start is not null order by xact_start;';
    }

    private function describeSession(Session $session): string
    {
        $minutes = number_format($session->transactionSeconds / 60, 1);

        return "pid {$session->pid}, idle {$minutes} min";
    }

    private function timeoutDescription(Settings $settings): string
    {
        $setting = $settings->get('idle_in_transaction_session_timeout');

        return $setting instanceof \Heyosseus\Vacuum\Values\Setting ? $setting->value.($setting->unit ?? '') : 'an unknown value';
    }

    /**
     * @return list<string>
     */
    private function toRow(Session $session): array
    {
        $minutes = number_format($session->transactionSeconds / 60, 1);
        $query = mb_strlen($session->query) > self::QUERY_PREVIEW
            ? mb_substr($session->query, 0, self::QUERY_PREVIEW).'…'
            : $session->query;

        return [
            (string) $session->pid,
            $session->state,
            "{$minutes} min",
            $query,
        ];
    }
}
