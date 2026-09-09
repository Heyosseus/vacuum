<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Schema\MigrationMap;
use Heyosseus\Vacuum\Schema\MigrationScanner;

function mapped(): MigrationMap
{
    return new MigrationMap(__DIR__.'/../../fixtures/migrations', new MigrationScanner);
}

function located(string $subject, ?string $table = 'public.orders'): Finding
{
    return new Finding(
        rule: 'unindexed-foreign-key',
        subject: $subject,
        severity: Severity::Warning,
        summary: 'stub',
        impact: 'stub',
        table: $table,
    );
}

it('anchors a column finding to the line the column was declared on', function (): void {
    $location = mapped()->locate(located('public.orders.customer_id'));

    expect($location?->line)->toBe(15)
        ->and($location?->file)->toContain('create_orders_table');
});

it('falls back to the table when the column is not in any migration', function (): void {
    // A column added by a raw DB::statement is still on a table the map knows.
    $location = mapped()->locate(located('public.orders.added_by_hand'));

    expect($location?->line)->toBe(13);
});

it('places a table-level finding on the create call', function (): void {
    expect(mapped()->locate(located('public.orders'))?->line)->toBe(13);
});

it('returns nothing for a table no migration declares', function (): void {
    expect(mapped()->locate(located('public.nowhere.column', table: 'public.nowhere')))->toBeNull();
});

it('returns nothing for a finding that is about no table', function (): void {
    expect(mapped()->locate(located('SchemaInspection', table: null)))->toBeNull();
});

it('places nothing from a migration whose table name is a variable', function (): void {
    expect(mapped()->locate(located('public.invisible.hidden_id', table: 'public.invisible')))->toBeNull();
});

it('survives a directory that does not exist', function (): void {
    $map = new MigrationMap(__DIR__.'/no-such-directory', new MigrationScanner);

    expect($map->locate(located('public.orders.customer_id')))->toBeNull();
});

it('scans the directory once and reuses the result on a second lookup', function (): void {
    $map = mapped();

    $map->locate(located('public.orders'));
    $second = $map->locate(located('public.orders.customer_id'));

    expect($second?->line)->toBe(15);
});

it('falls back to the unqualified entry when the scanner never recorded a schema', function (): void {
    // Migrations do not ordinarily write a schema -- Schema::create('orders', ...),
    // not Schema::create('tenant.orders', ...) -- so this is the everyday case for
    // any schema other than the default one the fixture happens to use: the entry
    // the scanner recorded is bare, and the qualified lookup has to miss before
    // the unqualified one gets a chance to hit.
    $location = mapped()->locate(located('tenant.orders.customer_id', table: 'tenant.orders'));

    expect($location?->line)->toBe(15)
        ->and($location?->file)->toContain('create_orders_table');
});

it('prefers a schema-qualified entry over an unqualified one from a different schema', function (): void {
    // A multi-schema application can write the schema directly --
    // Schema::create('tenant.orders', ...) -- and when it does, that entry has to
    // win the collision it would otherwise lose to public.orders's own migration:
    // reducing straight to the unqualified key is exactly the bug this fixes.
    $directory = sys_get_temp_dir().'/vacuum-migration-map-schema-'.bin2hex((string) getmypid());
    mkdir($directory, recursive: true);
    $file = $directory.'/2024_01_03_000000_create_tenant_orders_table.php';

    file_put_contents($file, <<<'PHP'
    <?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    return new class extends Migration
    {
        public function up(): void
        {
            Schema::create('tenant.orders', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id');
            });
        }
    };
    PHP);

    try {
        $map = new MigrationMap($directory, new MigrationScanner);
        $location = $map->locate(located('tenant.orders.customer_id', table: 'tenant.orders'));

        expect($location?->file)->toContain('create_tenant_orders_table')
            ->and($location?->line)->toBe(13);
    } finally {
        unlink($file);
        rmdir($directory);
    }
});

it('skips a glob match it cannot read as a file', function (): void {
    // A directory whose name happens to end in .php still matches the glob, and
    // file_get_contents on it fails the way an unreadable file would. Built under
    // the system temp directory rather than as a fixture, because an empty
    // directory is invisible to git and would not survive a checkout.
    $directory = sys_get_temp_dir().'/vacuum-migration-map-'.bin2hex((string) getmypid());
    mkdir($directory.'/unreadable.php', recursive: true);

    try {
        $map = new MigrationMap($directory, new MigrationScanner);

        expect($map->locate(located('public.orders.customer_id')))->toBeNull();
    } finally {
        rmdir($directory.'/unreadable.php');
        rmdir($directory);
    }
});
