<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Advisor;
use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Inspection;
use Heyosseus\Vacuum\Advisor\SchemaAdvisor;
use Heyosseus\Vacuum\Advisor\Severity;

// SchemaAdvisor is a concrete final class wrapping an Advisor, not an interface, so
// the stub is a real SchemaAdvisor around a stub Inspection rather than a bare
// object -- the same shape CheckCommandTest's advisorSees() uses one layer down.
function stubAdvisor(Finding ...$findings): void
{
    $inspection = new readonly class(array_values($findings)) implements Inspection
    {
        /** @param list<Finding> $findings */
        public function __construct(private array $findings) {}

        /** @return list<Finding> */
        public function findings(): array
        {
            return $this->findings;
        }
    };

    app()->instance(SchemaAdvisor::class, new SchemaAdvisor(new Advisor([$inspection])));
}

function schemaFinding(Severity $severity = Severity::Warning): Finding
{
    return new Finding(
        rule: 'unindexed-foreign-key',
        subject: 'public.orders.customer_id',
        severity: $severity,
        summary: 'No index behind the foreign key.',
        impact: 'Deletes on the parent scan the child.',
        remediation: 'CREATE INDEX CONCURRENTLY ON "public"."orders" ("customer_id");',
        table: 'public.orders',
    );
}

it('passes on a clean schema', function (): void {
    stubAdvisor();

    $this->artisan('vacuum:lint', ['--no-interaction' => true])
        ->expectsOutputToContain('100 / 100')
        ->assertExitCode(0);
});

it('fails on a warning by default', function (): void {
    // check defaults to critical because a drifting database should not redden a
    // build. A schema defect was wrong the moment it was written, so lint does
    // not wait for it to become critical.
    stubAdvisor(schemaFinding());

    $this->artisan('vacuum:lint', ['--no-interaction' => true])->assertExitCode(1);
});

it('passes the same finding when the bar is critical', function (): void {
    stubAdvisor(schemaFinding());

    $this->artisan('vacuum:lint', ['--fail-on' => 'critical', '--no-interaction' => true])->assertExitCode(0);
});

it('refuses a severity it does not know', function (): void {
    stubAdvisor();

    $this->artisan('vacuum:lint', ['--fail-on' => 'catastrophic', '--no-interaction' => true])
        ->assertExitCode(2);
});

it('fails rather than passes when Vacuum is disabled', function (): void {
    // A gate that goes green because it never looked is worse than no gate, and
    // that is more true in a pipeline than anywhere else.
    config()->set('vacuum.enabled', false);

    $this->artisan('vacuum:lint', ['--no-interaction' => true])->assertExitCode(2);
});

it('emits a json document a pipeline can parse', function (): void {
    stubAdvisor(schemaFinding());

    // Artisan::call, not $this->artisan(): the PendingCommand's output does not
    // reach Artisan::output(), and Artisan::output() flushes, so it is read once
    // into a variable and asserted from there.
    $exit = Artisan::call('vacuum:lint', ['--format' => 'json', '--no-interaction' => true]);
    $document = json_decode(Artisan::output(), true);

    expect($exit)->toBe(1);

    expect($document)->toHaveKeys(['score', 'grade', 'failed', 'deductions', 'findings'])
        ->and($document['failed'])->toBeTrue()
        ->and($document['findings'][0]['rule'])->toBe('unindexed-foreign-key')
        ->and($document['findings'][0]['subject'])->toBe('public.orders.customer_id')
        ->and($document['findings'][0]['table'])->toBe('public.orders');
});

it('says the baseline is not here yet rather than ignoring the flag', function (): void {
    // The option exists so that the command's signature does not change when the
    // baseline lands. Accepting it silently would be worse than refusing it.
    stubAdvisor();

    $this->artisan('vacuum:lint', ['--generate-baseline' => true, '--no-interaction' => true])
        ->assertExitCode(2);

    $this->artisan('vacuum:lint', ['--no-baseline' => true, '--no-interaction' => true])
        ->assertExitCode(2);

    $this->artisan('vacuum:lint', ['--baseline' => 'baseline.json', '--no-interaction' => true])
        ->assertExitCode(2);
});
