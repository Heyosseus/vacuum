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
 */
final readonly class Setting
{
    public function __construct(
        public string $name,
        public string $value,
        public ?string $unit,
        public string $context,
        public string $source,
        public string $bootValue,
        public bool $pendingRestart,
    ) {}

    /**
     * Whether this is still the value PostgreSQL shipped with.
     *
     * Compared against boot_val rather than reset_val: the question is whether
     * anybody ever made a decision here, not what this session happens to see.
     */
    public function isDefault(): bool
    {
        return $this->value === $this->bootValue;
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
