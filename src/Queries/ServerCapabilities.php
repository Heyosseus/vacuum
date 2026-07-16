<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Queries;

use Heyosseus\Vacuum\Database\ReadOnlyExecutor;
use Heyosseus\Vacuum\Support\Cast;
use Heyosseus\Vacuum\Values\Capabilities;

/**
 * Asks the server what it is prepared to tell us, before any panel assumes.
 */
final readonly class ServerCapabilities
{
    /**
     * The settings that decide whether a panel has anything to show. Tuning
     * settings such as shared_buffers belong to the configuration advice, which
     * is a different question asked by a different reader.
     *
     * @var list<string>
     */
    private const array SETTINGS = [
        'autovacuum',
        'shared_preload_libraries',
        'track_activities',
        'track_counts',
        'track_io_timing',
    ];

    public function __construct(private ReadOnlyExecutor $executor) {}

    public function probe(): Capabilities
    {
        $sql = <<<'SQL'
            SELECT
                current_setting('server_version_num') AS server_version,
                pg_has_role(current_user, 'pg_read_all_stats', 'USAGE') AS reads_all_statistics
            SQL;

        $server = $this->executor->select($sql)[0] ?? [];

        return new Capabilities(
            serverVersion: Cast::integer($server['server_version'] ?? null),
            extensions: $this->extensions(),
            settings: $this->settings(),
            readsAllStatistics: Cast::boolean($server['reads_all_statistics'] ?? null),
        );
    }

    /**
     * @return list<string>
     */
    private function extensions(): array
    {
        return array_map(
            $this->toName(...),
            $this->executor->select('SELECT extname FROM pg_extension ORDER BY extname'),
        );
    }

    /**
     * @return array<string, string>
     */
    private function settings(): array
    {
        $placeholders = implode(', ', array_fill(0, count(self::SETTINGS), '?'));

        $rows = $this->executor->select(
            "SELECT name, setting FROM pg_settings WHERE name IN ({$placeholders})",
            self::SETTINGS,
        );

        $settings = [];

        foreach ($rows as $row) {
            $settings[Cast::text($row['name'] ?? null)] = Cast::text($row['setting'] ?? null);
        }

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function toName(array $row): string
    {
        return Cast::text($row['extname'] ?? null);
    }
}
