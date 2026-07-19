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

    public function value(string $name): ?string
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
