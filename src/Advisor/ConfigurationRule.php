<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor;

use Heyosseus\Vacuum\Values\Settings;

/**
 * A rule that judges the settings pg_settings reports, with the metadata that
 * says what fixing one actually costs.
 *
 * Separate from SettingRule, which judges the handful of settings already
 * carried on Capabilities because a panel needed them for something else. This
 * contract exists for settings nothing else reads yet -- the full context,
 * source and pending_restart columns a configuration audit needs and a
 * capability probe has no reason to ask for.
 */
interface ConfigurationRule
{
    public function inspect(Settings $settings): ?Finding;
}
