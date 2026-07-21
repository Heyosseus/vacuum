<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Queries;

use Heyosseus\Vacuum\Database\ReadOnlyExecutor;
use Heyosseus\Vacuum\Support\Cast;
use Heyosseus\Vacuum\Values\Setting;
use Heyosseus\Vacuum\Values\Settings;

/**
 * The settings the configuration audit has an opinion about.
 *
 * Named rather than read wholesale: pg_settings carries some three hundred rows,
 * most of which no rule here judges, and reading all of them would put a page of
 * noise into a snapshot for the sake of a dozen questions.
 *
 * A role without pg_read_all_settings simply does not see the superuser-only
 * rows. They come back absent rather than as an error, which is why Settings
 * answers null for a name it never saw rather than inventing a default.
 */
final readonly class ServerSettings
{
    /**
     * @var list<string>
     */
    private const array AUDITED = [
        'autovacuum',
        'autovacuum_max_workers',
        'autovacuum_vacuum_cost_limit',
        'autovacuum_vacuum_scale_factor',
        'checkpoint_timeout',
        'idle_in_transaction_session_timeout',
        'jit',
        'lock_timeout',
        'max_connections',
        'max_wal_size',
        'shared_buffers',
        'statement_timeout',
        'track_counts',
        'track_io_timing',
        'transaction_timeout',
        'vacuum_cost_limit',
        'work_mem',
    ];

    public function __construct(private ReadOnlyExecutor $executor) {}

    public function read(): Settings
    {
        $placeholders = implode(', ', array_fill(0, count(self::AUDITED), '?'));

        // reset_val, not setting. Every query in this package runs inside a
        // transaction that has already issued SET LOCAL statement_timeout, so
        // pg_settings.setting reports the value Vacuum injected microseconds
        // earlier rather than the one the server is configured to. reset_val is
        // what a session would fall back to -- the role, database and
        // postgresql.conf value -- and is immune to SET LOCAL, which is the
        // only thing that makes a configuration audit of our own connection
        // honest.
        $rows = $this->executor->select(
            "SELECT name, setting, reset_val, unit, context, source, boot_val, pending_restart
             FROM pg_settings
             WHERE name IN ({$placeholders})",
            self::AUDITED,
        );

        $settings = [];

        foreach ($rows as $row) {
            $name = Cast::text($row['name'] ?? null);

            $settings[$name] = new Setting(
                name: $name,
                value: Cast::text($row['setting'] ?? null),
                resetValue: Cast::text($row['reset_val'] ?? null),
                unit: isset($row['unit']) ? Cast::text($row['unit']) : null,
                context: Cast::text($row['context'] ?? null),
                source: Cast::text($row['source'] ?? null),
                bootValue: Cast::text($row['boot_val'] ?? null),
                pendingRestart: Cast::boolean($row['pending_restart'] ?? null),
            );
        }

        return new Settings($settings);
    }
}
