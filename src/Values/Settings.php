<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Values;

/**
 * Every setting the configuration audit asked for, by name.
 *
 * A setting nobody could read answers null rather than a default, and a flag
 * nobody could read answers off. A configuration rule that cannot prove a safety
 * net is switched on must not report that it is: the whole point of the audit is
 * to find the nets that are missing.
 *
 * Every accessor here answers from the *configured* value -- pg_settings.reset_val
 * -- because every question asked of this class is a question about the server.
 * Reading pg_settings.setting instead would have each rule audit the transaction
 * Vacuum itself opened, which is how statement_timeout came to be reported as
 * 5000 on a server configured for thirty seconds. The session's own view is
 * still reachable through runtimeValue() for the caller that genuinely wants it,
 * and the default is the one that cannot be got wrong by accident.
 */
final readonly class Settings
{
    /**
     * @param  array<string, Setting>  $settings
     */
    public function __construct(private array $settings) {}

    public function get(string $name): ?Setting
    {
        return $this->settings[$name] ?? null;
    }

    /**
     * What the server is configured to, regardless of what this session was told.
     */
    public function value(string $name): ?string
    {
        return $this->get($name)?->resetValue;
    }

    /**
     * What this session sees, SET LOCAL and all. Almost never what a rule wants.
     */
    public function runtimeValue(string $name): ?string
    {
        return $this->get($name)?->value;
    }

    /**
     * The setting as a number, or null when it is absent or is not one.
     */
    public function integer(string $name): ?int
    {
        $value = $this->value($name);

        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * Whether a flag is off -- including when nobody could read it at all.
     */
    public function isOff(string $name): bool
    {
        return $this->value($name) !== 'on';
    }

    /**
     * @return array<string, Setting>
     */
    public function all(): array
    {
        return $this->settings;
    }

    /**
     * The settings somebody changed and the server has not picked up.
     *
     * @return array<string, Setting>
     */
    public function pendingRestart(): array
    {
        return array_filter($this->settings, static fn (Setting $setting): bool => $setting->pendingRestart);
    }
}
