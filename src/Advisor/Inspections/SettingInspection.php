<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor\Inspections;

use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Inspection;
use Heyosseus\Vacuum\Advisor\SettingRule;
use Heyosseus\Vacuum\Values\Capabilities;

/**
 * Puts every setting rule to the server itself.
 *
 * The only inspection that runs no query of its own: the settings were already
 * read, once, when the capabilities were probed.
 */
final readonly class SettingInspection implements Inspection
{
    /**
     * @param  iterable<SettingRule>  $rules
     */
    public function __construct(
        private Capabilities $capabilities,
        private iterable $rules,
    ) {}

    /**
     * @return list<Finding>
     */
    public function findings(): array
    {
        $findings = [];

        foreach ($this->rules as $rule) {
            $finding = $rule->inspect($this->capabilities);

            if ($finding instanceof Finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }
}
