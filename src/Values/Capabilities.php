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

    /**
     * Whether a library was loaded when the server started.
     *
     * shared_preload_libraries is a comma-separated list whose entries may be
     * quoted or padded, so each entry is compared whole rather than by
     * substring: a server preloading pg_stat_statements_plus has not preloaded
     * pg_stat_statements.
     */
    public function preloaded(string $library): bool
    {
        $entries = explode(',', $this->settings['shared_preload_libraries'] ?? '');

        foreach ($entries as $entry) {
            if (trim($entry, " \t\"") === $library) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether pg_stat_statements is actually collecting query statistics.
     *
     * CREATE EXTENSION succeeds even on a server that never preloaded the
     * library, and the view it creates then throws on the first read rather
     * than answers. Only the two together — created in this database and
     * loaded at startup — mean there is anything to ask.
     *
     * The server only shows shared_preload_libraries to roles carrying
     * pg_read_all_settings, so a probe that came home without it proves
     * nothing either way. That one reads as loaded rather than as off:
     * assuming off would blind the panel for the modest roles most
     * applications connect with, on the very servers where the extension
     * works.
     */
    public function tracksStatements(): bool
    {
        if (! $this->has('pg_stat_statements')) {
            return false;
        }

        if (! array_key_exists('shared_preload_libraries', $this->settings)) {
            return true;
        }

        return $this->preloaded('pg_stat_statements');
    }
}
