<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Values;

/**
 * A backend connected to the database, and what it is doing with itself.
 *
 * The durations are counted by PostgreSQL, not by PHP: the clock that matters is
 * the one the transaction started against.
 */
final readonly class Session
{
    /**
     * @param  int  $transactionSeconds  How long its transaction has been open, if it has one.
     * @param  int  $stateSeconds  How long it has been in the state it is in.
     * @param  list<int>  $blockedBy  The backends holding the locks it is waiting for.
     */
    public function __construct(
        public int $pid,
        public string $user,
        public string $application,
        public string $state,
        public string $query,
        public int $transactionSeconds,
        public int $stateSeconds,
        public array $blockedBy,
    ) {}

    public function active(): bool
    {
        return $this->state === 'active';
    }

    /**
     * A transaction the application opened and then walked away from. The aborted
     * flavour counts: a broken transaction holds its snapshot exactly as firmly as
     * a working one.
     */
    public function idleInTransaction(): bool
    {
        return in_array($this->state, ['idle in transaction', 'idle in transaction (aborted)'], true);
    }

    public function blocked(): bool
    {
        return $this->blockedBy !== [];
    }
}
