<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor;

use Heyosseus\Vacuum\Values\Capabilities;

/**
 * A rule that judges how the server is configured rather than what it contains.
 *
 * The subject is the whole server, so there is exactly one of it, and a rule here
 * is not looking for a bad table but a bad decision — usually one made during an
 * incident or a migration and never undone.
 */
interface SettingRule
{
    public function inspect(Capabilities $capabilities): ?Finding;
}
