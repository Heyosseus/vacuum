<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\ConfigurationRule;
use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Inspections\ConfigurationInspection;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Queries\ServerSettings;
use Heyosseus\Vacuum\Values\Setting;
use Heyosseus\Vacuum\Values\Settings;

it('reports what the configuration rules find, reading real settings from the server', function (): void {
    $rule = new class implements ConfigurationRule
    {
        public function inspect(Settings $settings): ?Finding
        {
            if (! $settings->get('shared_buffers') instanceof Setting) {
                return null;
            }

            return new Finding(
                rule: 'test-configuration-rule',
                subject: 'server',
                severity: Severity::Info,
                summary: 'shared_buffers was readable.',
                impact: 'None -- this rule exists only to prove the seam works.',
            );
        }
    };

    $inspection = new ConfigurationInspection(app(ServerSettings::class), [$rule]);

    $findings = $inspection->findings();

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->rule)->toBe('test-configuration-rule');
});

it('has nothing to say when no rule fires', function (): void {
    $rule = new class implements ConfigurationRule
    {
        public function inspect(Settings $settings): ?Finding
        {
            return null;
        }
    };

    $inspection = new ConfigurationInspection(app(ServerSettings::class), [$rule]);

    expect($inspection->findings())->toBe([]);
});
