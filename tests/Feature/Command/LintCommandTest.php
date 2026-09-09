<?php

declare(strict_types=1);

use Composer\InstalledVersions;
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

/**
 * Runs the callback with Composer's InstalledVersions replaced by a single,
 * fully controlled dataset, then restores exactly what was there before.
 *
 * InstalledVersions::reload() alone is not enough for this: it clears the
 * per-vendor-directory cache but not the flag that decides whether the real
 * registered class loaders are consulted at all, so this package's own
 * vendor/composer/installed.php -- which lists heyosseus/vacuum whether or not
 * it is the root, because in this checkout it genuinely is -- would still
 * answer every lookup before the reloaded data got a turn. Forcing
 * canGetVendors false is what actually isolates the test from the real
 * installation this suite runs inside of.
 *
 * @param  array{root: array{name: string, pretty_version: string, version: string, reference: string|null, type: string, install_path: string, aliases: array<int, string>, dev: bool}, versions: array<string, array{pretty_version?: string, version?: string, dev_requirement?: bool}>}  $data
 */
function withInstalledVersions(array $data, Closure $callback): void
{
    $canGetVendors = new ReflectionProperty(InstalledVersions::class, 'canGetVendors');
    $installed = new ReflectionProperty(InstalledVersions::class, 'installed');
    $installedByVendor = new ReflectionProperty(InstalledVersions::class, 'installedByVendor');
    $installedIsLocalDir = new ReflectionProperty(InstalledVersions::class, 'installedIsLocalDir');

    $original = [
        $canGetVendors->getValue(),
        $installed->getValue(),
        $installedByVendor->getValue(),
        $installedIsLocalDir->getValue(),
    ];

    $canGetVendors->setValue(null, false);
    InstalledVersions::reload($data);

    try {
        $callback();
    } finally {
        $canGetVendors->setValue(null, $original[0]);
        $installed->setValue(null, $original[1]);
        $installedByVendor->setValue(null, $original[2]);
        $installedIsLocalDir->setValue(null, $original[3]);
    }
}

beforeEach(function (): void {
    $this->originalStepSummary = getenv('GITHUB_STEP_SUMMARY');
    $this->stepSummaryFile = null;
});

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

it('suppresses a finding listed in the baseline', function (): void {
    stubAdvisor(schemaFinding());

    $path = base_path('vacuum-baseline.json');
    file_put_contents($path, json_encode([
        'findings' => ['unindexed-foreign-key' => ['public.orders.customer_id']],
    ]));

    $exit = Artisan::call('vacuum:lint', ['--no-interaction' => true]);

    expect($exit)->toBe(0);
});

it('says how many it suppressed rather than quietly scoring around them', function (): void {
    // A green number computed over findings it never mentioned is exactly the
    // lie this package exists to argue against.
    stubAdvisor(schemaFinding());

    $path = base_path('vacuum-baseline.json');
    file_put_contents($path, json_encode([
        'findings' => ['unindexed-foreign-key' => ['public.orders.customer_id']],
    ]));

    Artisan::call('vacuum:lint', ['--no-interaction' => true]);
    $output = Artisan::output();

    expect($output)->toContain('1 suppressed by the baseline');
});

it('ignores the baseline when told to', function (): void {
    stubAdvisor(schemaFinding());

    $path = base_path('vacuum-baseline.json');
    file_put_contents($path, json_encode([
        'findings' => ['unindexed-foreign-key' => ['public.orders.customer_id']],
    ]));

    $exit = Artisan::call('vacuum:lint', ['--no-baseline' => true, '--no-interaction' => true]);

    expect($exit)->toBe(1);
});

it('writes a baseline and exits zero even with findings outstanding', function (): void {
    stubAdvisor(schemaFinding());

    $path = base_path('vacuum-baseline.json');

    $exit = Artisan::call('vacuum:lint', ['--generate-baseline' => true, '--no-interaction' => true]);

    $written = json_decode((string) file_get_contents($path), true);

    expect($exit)->toBe(0)
        ->and($written['findings']['unindexed-foreign-key'])->toBe(['public.orders.customer_id']);
});

it('stamps the baseline with vacuum\'s own installed version, not the application\'s', function (): void {
    stubAdvisor(schemaFinding());

    $path = base_path('vacuum-baseline.json');

    withInstalledVersions([
        'root' => [
            'name' => 'acme/app',
            'pretty_version' => 'v1.0.0',
            'version' => '1.0.0.0',
            'reference' => null,
            'type' => 'library',
            'install_path' => __DIR__,
            'aliases' => [],
            'dev' => true,
        ],
        'versions' => [
            'acme/app' => ['pretty_version' => 'v1.0.0', 'dev_requirement' => false],
            'heyosseus/vacuum' => ['pretty_version' => '1.2.3', 'dev_requirement' => false],
        ],
    ], function (): void {
        Artisan::call('vacuum:lint', ['--generate-baseline' => true, '--no-interaction' => true]);
    });

    $written = json_decode((string) file_get_contents($path), true);

    expect($written['vacuum'])->toBe('1.2.3');
});

it('falls back to the root package version when composer cannot report vacuum by name', function (): void {
    // This is what makes this package's own test suite work: there vacuum is
    // genuinely the root, and the root's version is the only one there is.
    stubAdvisor(schemaFinding());

    $path = base_path('vacuum-baseline.json');

    withInstalledVersions([
        'root' => [
            'name' => 'heyosseus/vacuum',
            'pretty_version' => 'dev-feature',
            'version' => 'dev-feature',
            'reference' => null,
            'type' => 'library',
            'install_path' => __DIR__,
            'aliases' => [],
            'dev' => true,
        ],
        'versions' => [
            // No heyosseus/vacuum entry: Composer has nothing to report by name.
        ],
    ], function (): void {
        Artisan::call('vacuum:lint', ['--generate-baseline' => true, '--no-interaction' => true]);
    });

    $written = json_decode((string) file_get_contents($path), true);

    expect($written['vacuum'])->toBe('dev-feature');
});

it('fails rather than claiming success when the baseline cannot be written', function (): void {
    // The easiest trigger for an unwritable path: a --baseline= pointing into a
    // directory that was never created.
    stubAdvisor(schemaFinding());

    $exit = Artisan::call('vacuum:lint', [
        '--generate-baseline' => true,
        '--baseline' => 'no-such-directory/vacuum-baseline.json',
        '--no-interaction' => true,
    ]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('Could not write the baseline to');
});

it('reads a baseline from an explicit path', function (): void {
    stubAdvisor(schemaFinding());

    $path = base_path('custom-baseline.json');
    file_put_contents($path, json_encode([
        'findings' => ['unindexed-foreign-key' => ['public.orders.customer_id']],
    ]));

    $exit = Artisan::call('vacuum:lint', ['--baseline' => 'custom-baseline.json', '--no-interaction' => true]);

    expect($exit)->toBe(0);
});

it('reports a baseline entry that no longer matches, without failing the build', function (): void {
    stubAdvisor();

    $path = base_path('vacuum-baseline.json');
    file_put_contents($path, json_encode([
        'findings' => ['unindexed-foreign-key' => ['public.orders.long_since_fixed']],
    ]));

    $exit = Artisan::call('vacuum:lint', ['--no-interaction' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0)->and($output)->toContain('baseline-stale');
});

it('does not fail the build on a stale entry even at --fail-on=info', function (): void {
    // Stale entries are Info because the defect being gone is good news, and
    // good news must never be able to redden a build. Before this fix they were
    // fed to the same bar as everything else, so fixing something could fail
    // the build the moment somebody set --fail-on=info.
    stubAdvisor();

    $path = base_path('vacuum-baseline.json');
    file_put_contents($path, json_encode([
        'findings' => ['unindexed-foreign-key' => ['public.orders.long_since_fixed']],
    ]));

    $exit = Artisan::call('vacuum:lint', ['--fail-on' => 'info', '--no-interaction' => true]);

    expect($exit)->toBe(0);
});

it('counts suppressed findings in the json document', function (): void {
    stubAdvisor(schemaFinding());

    $path = base_path('vacuum-baseline.json');
    file_put_contents($path, json_encode([
        'findings' => ['unindexed-foreign-key' => ['public.orders.customer_id']],
    ]));

    Artisan::call('vacuum:lint', ['--format' => 'json', '--no-interaction' => true]);
    $document = json_decode(Artisan::output(), true);

    expect($document['suppressed'])->toBe(1)->and($document['findings'])->toBe([]);
});

it('emits github annotations when asked for them', function (): void {
    stubAdvisor(schemaFinding());

    Artisan::call('vacuum:lint', ['--format' => 'github', '--no-interaction' => true]);

    // Read once: Artisan::output() flushes the buffer.
    $output = Artisan::output();

    expect($output)->toContain('::warning ')->and($output)->not->toContain('/ 100');
});

it('reports how many findings the baseline suppressed as a notice', function (): void {
    // The pull request is the one place four hundred silently-suppressed
    // findings matter most, so this format must not stay quiet about the count
    // the way the original implementation did.
    stubAdvisor(schemaFinding());

    $path = base_path('vacuum-baseline.json');
    file_put_contents($path, json_encode([
        'findings' => ['unindexed-foreign-key' => ['public.orders.customer_id']],
    ]));

    Artisan::call('vacuum:lint', ['--format' => 'github', '--no-interaction' => true]);

    expect(Artisan::output())->toContain('::notice::1 suppressed by the baseline.');
});

it('appends a summary table when the runner offers one', function (): void {
    stubAdvisor(schemaFinding());

    $summary = tempnam(sys_get_temp_dir(), 'vacuum-summary');
    $this->stepSummaryFile = $summary;
    putenv('GITHUB_STEP_SUMMARY='.$summary);

    Artisan::call('vacuum:lint', ['--format' => 'github', '--no-interaction' => true]);

    $written = (string) file_get_contents($summary);

    expect($written)->toContain('| Severity | Rule | Subject |');
});

it('warns without failing the command when the step summary cannot be written', function (): void {
    // Unlike the baseline, losing the step summary loses nothing the caller
    // depended on -- every finding is already in the annotations above it -- so
    // this must not be mistaken for having worked, but it also must not fail
    // the build over a convenience the runner merely offers.
    stubAdvisor();

    $directory = sys_get_temp_dir().'/vacuum-step-summary-'.bin2hex((string) getmypid());
    putenv('GITHUB_STEP_SUMMARY='.$directory.'/no-such-directory/summary.md');

    $exit = Artisan::call('vacuum:lint', ['--format' => 'github', '--no-interaction' => true]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('Could not append the step summary to');
});

afterEach(function (): void {
    // A leaked baseline file would silently suppress findings in every later
    // test in the suite, so cleanup here does not depend on a preceding test
    // actually reaching its own unlink() call.
    foreach (['vacuum-baseline.json', 'custom-baseline.json'] as $file) {
        $path = base_path($file);

        if (is_file($path)) {
            unlink($path);
        }
    }

    // Only the file this file's own tests created, and only if one was made --
    // GITHUB_STEP_SUMMARY is set by the real runner to a file the job owns
    // inside this project's own GitHub Actions run, and deleting whatever it
    // currently points at would delete that runner's summary out from under it.
    if (is_string($this->stepSummaryFile) && is_file($this->stepSummaryFile)) {
        unlink($this->stepSummaryFile);
    }

    // Restored to whatever it was before this file's tests ran, rather than
    // cleared unconditionally, for the same reason: outside this suite the
    // variable is real and belongs to the runner, not to this test file.
    if ($this->originalStepSummary === false) {
        putenv('GITHUB_STEP_SUMMARY');
    } else {
        putenv('GITHUB_STEP_SUMMARY='.$this->originalStepSummary);
    }
});
