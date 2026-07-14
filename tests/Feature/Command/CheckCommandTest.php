<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Advisor;
use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Inspection;
use Heyosseus\Vacuum\Advisor\Severity;
use Symfony\Component\Console\Command\Command;

/**
 * The command is given its findings rather than a database to find them in. What is
 * under test here is the gate: what it prints, and what it exits with. The rules
 * that produce the findings are tested where they live.
 */
function advisorSees(array $findings): void
{
    $inspection = new readonly class($findings) implements Inspection
    {
        public function __construct(private array $findings) {}

        public function findings(): array
        {
            return $this->findings;
        }
    };

    app()->instance(Advisor::class, new Advisor([$inspection]));
}

function reported(Severity $severity, string $rule = 'dead-tuples'): Finding
{
    return new Finding(
        rule: $rule,
        subject: 'public.orders',
        severity: $severity,
        summary: 'Something is the matter with this table.',
        impact: 'And this is what it costs you.',
        remediation: 'VACUUM ANALYZE "public"."orders";',
    );
}

it('passes a database nobody has a complaint about', function (): void {
    advisorSees([]);

    $code = Artisan::call('vacuum:check');
    $output = Artisan::output();

    expect($code)->toBe(Command::SUCCESS)
        ->and($output)->toContain('Nothing to report')
        ->and($output)->toContain('100');
});

it('fails the build on a critical finding', function (): void {
    advisorSees([reported(Severity::Critical, 'wraparound')]);

    $code = Artisan::call('vacuum:check');
    $output = Artisan::output();

    expect($code)->toBe(Command::FAILURE)
        ->and($output)->toContain('wraparound')
        ->and($output)->toContain('public.orders')
        // Printed for a person to run, never run by this command.
        ->and($output)->toContain('VACUUM ANALYZE');
});

it('lets a warning through unless it is told not to', function (): void {
    advisorSees([reported(Severity::Warning)]);

    $this->artisan('vacuum:check')->assertExitCode(Command::SUCCESS);
    $this->artisan('vacuum:check --fail-on=warning')->assertExitCode(Command::FAILURE);
});

it('can be told to report everything and fail at nothing', function (): void {
    advisorSees([reported(Severity::Critical)]);

    $this->artisan('vacuum:check --fail-on=never')
        ->expectsOutputToContain('dead-tuples')
        ->assertExitCode(Command::SUCCESS);
});

it('fails on an info finding only when it is asked to', function (): void {
    // An info finding is a fact rather than a fault: the server saying what it
    // cannot see. It costs nothing on the dashboard and fails nothing here, unless
    // somebody sets the bar there deliberately.
    advisorSees([reported(Severity::Info, 'statistics-disabled')]);

    $this->artisan('vacuum:check')->assertExitCode(Command::SUCCESS);
    $this->artisan('vacuum:check --fail-on=warning')->assertExitCode(Command::SUCCESS);
    $this->artisan('vacuum:check --fail-on=info')->assertExitCode(Command::FAILURE);
});

it('says which threshold it was given when it does not recognise it', function (): void {
    advisorSees([]);

    $this->artisan('vacuum:check --fail-on=disaster')
        ->expectsOutputToContain('disaster')
        ->assertExitCode(Command::INVALID);
});

it('refuses to pass a check it never made', function (): void {
    // The master switch turns Vacuum off. A CI gate that goes green because it did
    // not look is worse than no gate at all, so this is the one case where finding
    // nothing is not a pass.
    config()->set('vacuum.enabled', false);

    $this->artisan('vacuum:check')
        ->expectsOutputToContain('disabled')
        ->assertExitCode(Command::INVALID);
});

it('reports as json for something other than a person to read', function (): void {
    advisorSees([reported(Severity::Critical, 'wraparound')]);

    $code = Artisan::call('vacuum:check', ['--format' => 'json']);
    $report = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($code)->toBe(Command::FAILURE);

    expect($report['score'])->toBe(85)
        ->and($report['grade'])->toBe('D')
        ->and($report['failed'])->toBeTrue()
        ->and($report['findings'])->toHaveCount(1)
        ->and($report['findings'][0]['rule'])->toBe('wraparound')
        ->and($report['findings'][0]['severity'])->toBe('critical')
        ->and($report['findings'][0]['remediation'])->toBe('VACUUM ANALYZE "public"."orders";');
});

it('prints the score a person would have seen on the dashboard', function (): void {
    advisorSees([reported(Severity::Warning)]);

    $code = Artisan::call('vacuum:check');
    $output = Artisan::output();

    // The same arithmetic as the page: a hundred, minus what the findings cost.
    expect($code)->toBe(Command::SUCCESS)
        ->and($output)->toContain('95')
        ->and($output)->toContain('Grade A')
        ->and($output)->toContain('dead-tuples');
});
