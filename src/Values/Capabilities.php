<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Values;

/**
 * What the server Vacuum is pointed at will actually let it see.
 *
 * Panels differ in what they need: query analytics wants pg_stat_statements,
 * the I/O panel is blank unless track_io_timing is on, and a role without
 * pg_read_all_stats sees other sessions as null rather than as an error. Asking
 * these questions once, up front, is what lets a panel explain itself instead of
 * rendering a grid of zeroes or a 500.
 */
final readonly class Capabilities
{
    /**
     * @param  int  $serverVersion  As PostgreSQL reports it: 170005 for 17.5.
     * @param  list<string>  $extensions
     * @param  array<string, string>  $settings
     */
    public function __construct(
        public int $serverVersion,
        public array $extensions,
        public array $settings,
        public bool $readsAllStatistics,
    ) {}

    public function majorVersion(): int
    {
        return intdiv($this->serverVersion, 10_000);
    }

    public function atLeast(int $majorVersion): bool
    {
        return $this->majorVersion() >= $majorVersion;
    }

    public function has(string $extension): bool
    {
        return in_array($extension, $this->extensions, true);
    }

    /**
     * A setting nobody probed reads as off: a panel that cannot prove a feature
     * is on should assume it is off rather than promise data it cannot produce.
     */
    public function enabled(string $setting): bool
    {
        return ($this->settings[$setting] ?? 'off') === 'on';
    }
}
