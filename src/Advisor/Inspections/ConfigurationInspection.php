<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor\Inspections;

use Heyosseus\Vacuum\Advisor\ConfigurationRule;
use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Inspection;
use Heyosseus\Vacuum\Queries\ServerSettings;

/**
 * Puts every configuration rule to the settings the audit asked pg_settings for.
 *
 * Reads its own settings rather than sharing SettingInspection's Capabilities:
 * the configuration audit wants the full context, source and pending_restart
 * columns, which a capability probe has no reason to carry for every panel that
 * merely wants to know whether a feature is on.
 */
final readonly class ConfigurationInspection implements Inspection
{
    /**
     * @param  iterable<ConfigurationRule>  $rules
     */
    public function __construct(
        private ServerSettings $settings,
        private iterable $rules,
    ) {}

    /**
     * @return list<Finding>
     */
    public function findings(): array
    {
        $settings = $this->settings->read();

        $findings = [];

        foreach ($this->rules as $rule) {
            $finding = $rule->inspect($settings);

            if ($finding instanceof Finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }
}
