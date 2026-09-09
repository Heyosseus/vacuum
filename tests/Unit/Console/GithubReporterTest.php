<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Console\Support\GithubReporter;
use Heyosseus\Vacuum\Schema\MigrationMap;
use Heyosseus\Vacuum\Schema\MigrationScanner;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

function annotated(array $findings, int $suppressed = 0): string
{
    $buffer = new BufferedOutput;
    $output = new OutputStyle(new ArrayInput([]), $buffer);
    $map = new MigrationMap(__DIR__.'/../../fixtures/migrations', new MigrationScanner);

    (new GithubReporter($map))->report($output, $findings, $suppressed);

    return $buffer->fetch();
}

function annotatable(
    Severity $severity = Severity::Warning,
    string $summary = 'No index behind the foreign key.',
    ?string $remediation = null,
    string $subject = 'public.orders.customer_id',
): Finding {
    return new Finding(
        rule: 'unindexed-foreign-key',
        subject: $subject,
        severity: $severity,
        summary: $summary,
        impact: 'stub',
        remediation: $remediation,
        table: 'public.orders',
    );
}

it('anchors an annotation to the migration that introduced it', function (): void {
    expect(annotated([annotatable()]))
        ->toContain('::warning file=')
        ->and(annotated([annotatable()]))->toContain('create_orders_table')
        ->and(annotated([annotatable()]))->toContain('line=15');
});

it('maps severity onto the three levels GitHub understands', function (): void {
    expect(annotated([annotatable(Severity::Critical)]))->toStartWith('::error ')
        ->and(annotated([annotatable(Severity::Warning)]))->toStartWith('::warning ')
        ->and(annotated([annotatable(Severity::Info)]))->toStartWith('::notice ')
        ->and(annotated([annotatable(Severity::Unknown)]))->toStartWith('::notice ');
});

it('emits an annotation with no anchor when the map cannot place it', function (): void {
    // GitHub attaches an unanchored annotation to the workflow rather than
    // dropping it, so a finding the map cannot place is still seen.
    $orphan = new Finding(
        rule: 'missing-primary-key',
        subject: 'public.nowhere',
        severity: Severity::Warning,
        summary: 'stub',
        impact: 'stub',
        table: 'public.nowhere',
    );

    expect(annotated([$orphan]))->toContain('::warning title=')
        ->and(annotated([$orphan]))->not->toContain('file=');
});

it('escapes the characters that would otherwise break the command', function (): void {
    // A remediation is multi-line SQL and a summary can contain a comma, so an
    // unescaped implementation mangles exactly the findings people most want to
    // read.
    $awkward = annotatable(
        summary: "100% wrong, on two lines\nlike this",
        remediation: "CREATE INDEX a;\nCREATE INDEX b;",
    );

    $line = annotated([$awkward]);

    expect($line)->toContain('100%25 wrong')
        ->and($line)->toContain('%0A')
        ->and(substr_count(trim($line), "\n"))->toBe(0);
});

it('escapes a colon inside a property value', function (): void {
    // A colon reaches a property value only through the migration file's absolute
    // path, and only a Windows path begins "C:\\". Left unescaped it terminates the
    // property and swallows the rest of the annotation.
    $line = annotated([annotatable()]);

    expect($line)->not->toMatch('/file=[A-Za-z]:/')
        ->and($line)->toContain('%3A');
})->skipOnLinux()->skipOnMac();

it('renders a markdown table for the step summary', function (): void {
    $summary = (new GithubReporter(
        new MigrationMap(__DIR__.'/../../fixtures/migrations', new MigrationScanner)
    ))->summary([annotatable()]);

    expect($summary)->toContain('| Severity | Rule | Subject |')
        ->and($summary)->toContain('unindexed-foreign-key')
        ->and($summary)->toContain('public.orders.customer_id');
});

it('anchors the annotation with a path relative to the repository root', function (): void {
    // GitHub matches an annotation's `file` against the diff by resolving it
    // against the repository root, not the runner's filesystem. The map's own
    // paths are absolute -- correct for every other consumer -- so this has to be
    // fixed on the way out, and it has to hold on Windows, where an absolute path
    // carries a drive letter and backslashes, exactly as it does on POSIX.
    $directory = base_path('database/migrations');
    $created = ! is_dir($directory);

    if ($created) {
        mkdir($directory, 0o777, true);
    }

    $file = $directory.'/2024_01_05_000000_create_relative_path_table.php';
    file_put_contents($file, <<<'PHP'
    <?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    return new class extends Migration
    {
        public function up(): void
        {
            Schema::create('relative_path', function (Blueprint $table): void {
                $table->id();
            });
        }
    };
    PHP);

    try {
        $map = new MigrationMap($directory, new MigrationScanner);
        $buffer = new BufferedOutput;
        $output = new OutputStyle(new ArrayInput([]), $buffer);

        $finding = new Finding(
            rule: 'missing-primary-key',
            subject: 'public.relative_path',
            severity: Severity::Warning,
            summary: 'stub',
            impact: 'stub',
            table: 'public.relative_path',
        );

        (new GithubReporter($map))->report($output, [$finding]);
        $line = $buffer->fetch();

        expect($line)->toContain('file=database/migrations/')
            ->and($line)->not->toMatch('#file=/#')
            ->and($line)->not->toMatch('#file=[A-Za-z]:#');
    } finally {
        unlink($file);

        if ($created) {
            rmdir($directory);
        }
    }
});

it('reports how many findings the baseline suppressed', function (): void {
    // The pull request is the one place four hundred silently-suppressed findings
    // matter most, so this is the one format that must not stay quiet about the
    // count the way the original implementation did.
    expect(annotated([annotatable()], suppressed: 3))
        ->toContain('::notice::3 suppressed by the baseline.');
});

it('says nothing about suppression when nothing was suppressed', function (): void {
    expect(annotated([annotatable()]))->not->toContain('suppressed by the baseline');
});
