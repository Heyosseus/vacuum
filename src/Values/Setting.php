<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Values;

/**
 * One row of pg_settings: a server setting, and what it would take to change it.
 *
 * The value on its own is only half an answer. PostgreSQL sorts every setting
 * into a context that decides what changing it costs -- work_mem is a SET away,
 * max_wal_size wants a reload, shared_buffers wants the server stopped, and
 * block_size wants a different build entirely. Advice that does not say which is
 * advice a reader cannot plan around.
 *
 * Two values are carried, and which one a rule reads is the difference between
 * auditing the server and auditing Vacuum. $value is pg_settings.setting: what
 * this session sees, including anything SET LOCAL has done to it -- and Vacuum's
 * own executor sets statement_timeout on every connection it opens. $resetValue
 * is pg_settings.reset_val: what the session would fall back to, which is the
 * role, database and postgresql.conf value and the only one of the two that
 * describes the server rather than the observer.
 */
final readonly class Setting
{
    public function __construct(
        public string $name,
        public string $value,
        public string $resetValue,
        public ?string $unit,
        public string $context,
        public string $source,
        public string $bootValue,
        public bool $pendingRestart,
    ) {}

    /**
     * Whether this is still the value PostgreSQL shipped with.
     *
     * The configured value against boot_val: the question is whether anybody
     * ever made a decision here, and a SET LOCAL this package issued is not
     * somebody making a decision.
     */
    public function isDefault(): bool
    {
        return $this->resetValue === $this->bootValue;
    }

    /**
     * What a reader has to do to change this, in one word.
     *
     * Deliberately coarser than PostgreSQL's own contexts, which distinguish
     * things a reader does not care about: superuser and user both mean "a SET
     * changes it now", and backend and superuser-backend both mean a reload that
     * only reaches sessions opened afterwards. An unrecognised context reads as
     * a restart, because the expensive answer is the safe one to be wrong about.
     */
    public function changeRequires(): string
    {
        return match ($this->context) {
            'internal' => 'rebuild',
            'user', 'superuser' => 'session',
            'sighup' => 'reload',
            'backend', 'superuser-backend' => 'reconnect',
            default => 'restart',
        };
    }
}
