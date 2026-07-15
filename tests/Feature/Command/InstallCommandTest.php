<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Filament\Install\SyntaxChecker;
use Symfony\Component\Console\Command\Command;

/*
 * The command's job is to wire the plugin into a real panel provider and, whenever
 * it cannot do that safely, to say exactly what to do by hand. Every test points it
 * at a throwaway provider file so the edit is real but nobody's app is touched.
 */

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/vacuum-install-'.bin2hex((string) getmypid());

    if (! is_dir($this->dir)) {
        mkdir($this->dir, 0o777, true);
    }

    foreach (glob($this->dir.'/*') ?: [] as $file) {
        unlink($file);
    }
});

afterEach(function (): void {
    foreach (glob($this->dir.'/*') ?: [] as $file) {
        unlink($file);
    }

    if (is_dir($this->dir)) {
        rmdir($this->dir);
    }
});

function writeProvider(string $dir, string $name, string $chain = "            ->default()\n            ->id('admin')"): string
{
    $source = <<<PHP
    <?php

    namespace App\\Providers\\Filament;

    use Filament\\Panel;
    use Filament\\PanelProvider;

    class {$name} extends PanelProvider
    {
        public function panel(Panel \$panel): Panel
        {
            return \$panel
    {$chain};
        }
    }
    PHP;

    $path = $dir.'/'.$name.'.php';
    file_put_contents($path, $source);

    return $path;
}

/** Force the syntax gate's verdict so both the kept and the rolled-back paths run. */
function fakeSyntax(bool $verdict): void
{
    app()->instance(SyntaxChecker::class, new readonly class($verdict) implements SyntaxChecker
    {
        public function __construct(private bool $verdict) {}

        public function check(string $file): bool
        {
            return $this->verdict;
        }
    });
}

it('refuses to be told to serve both uis at once', function (): void {
    $this->artisan('vacuum:install --blade --filament')
        ->expectsOutputToContain('one')
        ->assertExitCode(Command::INVALID);
});

it('sets up the blade dashboard when asked for blade', function (): void {
    $code = Artisan::call('vacuum:install', ['--blade' => true]);

    expect($code)->toBe(Command::SUCCESS)
        ->and(Artisan::output())->toContain('/vacuum');
});

it('tells you to make a panel when there is none', function (): void {
    $code = Artisan::call('vacuum:install', ['--filament' => true, '--panel-dir' => $this->dir]);

    expect($code)->toBe(Command::SUCCESS)
        ->and(Artisan::output())->toContain('filament:install');
});

it('looks in the app providers directory by default', function (): void {
    // No --panel-dir given, so it falls back to app/Providers/Filament. A bare test
    // app has none, which is exactly the "make a panel first" case.
    $code = Artisan::call('vacuum:install', ['--filament' => true]);

    expect($code)->toBe(Command::SUCCESS)
        ->and(Artisan::output())->toContain('filament:install');
});

it('wires the plugin into the one panel it finds', function (): void {
    $path = writeProvider($this->dir, 'AdminPanelProvider');

    $code = Artisan::call('vacuum:install', ['--filament' => true, '--panel-dir' => $this->dir, '--force' => true]);

    expect($code)->toBe(Command::SUCCESS)
        ->and(Artisan::output())->toContain('VACUUM_UI=filament')
        ->and(file_get_contents($path))->toContain('VacuumPlugin::make()');
});

it('says so when the one panel already has the plugin', function (): void {
    writeProvider($this->dir, 'AdminPanelProvider', "            ->default()\n            ->plugin(\Heyosseus\Vacuum\Filament\VacuumPlugin::make())");

    $code = Artisan::call('vacuum:install', ['--filament' => true, '--panel-dir' => $this->dir, '--force' => true]);

    expect($code)->toBe(Command::SUCCESS)
        ->and(Artisan::output())->toContain('already');
});

it('prints instructions when it cannot parse the panel', function (): void {
    file_put_contents($this->dir.'/AdminPanelProvider.php', "<?php\n\nclass AdminPanelProvider {}\n");

    $code = Artisan::call('vacuum:install', ['--filament' => true, '--panel-dir' => $this->dir, '--force' => true]);

    expect($code)->toBe(Command::SUCCESS)
        ->and(Artisan::output())->toContain('VacuumPlugin::make()');
});

it('rolls back and prints instructions when its edit would not parse', function (): void {
    $path = writeProvider($this->dir, 'AdminPanelProvider');
    $original = (string) file_get_contents($path);
    fakeSyntax(false);

    $code = Artisan::call('vacuum:install', ['--filament' => true, '--panel-dir' => $this->dir, '--force' => true]);

    expect($code)->toBe(Command::SUCCESS)
        ->and(Artisan::output())->toContain('by hand')
        ->and(file_get_contents($path))->toBe($original);
});

it('asks before editing and does nothing when refused', function (): void {
    $path = writeProvider($this->dir, 'AdminPanelProvider');
    $original = (string) file_get_contents($path);

    $this->artisan('vacuum:install', ['--filament' => true, '--panel-dir' => $this->dir])
        ->expectsConfirmation('Add Vacuum to AdminPanelProvider.php?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect(file_get_contents($path))->toBe($original);
});

it('edits after the edit is confirmed', function (): void {
    $path = writeProvider($this->dir, 'AdminPanelProvider');

    $this->artisan('vacuum:install', ['--filament' => true, '--panel-dir' => $this->dir])
        ->expectsConfirmation('Add Vacuum to AdminPanelProvider.php?', 'yes')
        ->assertExitCode(Command::SUCCESS);

    expect(file_get_contents($path))->toContain('VacuumPlugin::make()');
});

it('does not silently edit a file when it cannot ask', function (): void {
    // Non-interactive and without --force: the safe default is to print, not write.
    $path = writeProvider($this->dir, 'AdminPanelProvider');
    $original = (string) file_get_contents($path);

    $code = Artisan::call('vacuum:install', ['--filament' => true, '--panel-dir' => $this->dir]);

    expect($code)->toBe(Command::SUCCESS)
        ->and(file_get_contents($path))->toBe($original);
});

it('picks the named panel out of several', function (): void {
    writeProvider($this->dir, 'AdminPanelProvider');
    $app = writeProvider($this->dir, 'AppPanelProvider');

    $code = Artisan::call('vacuum:install', [
        '--filament' => true, '--panel-dir' => $this->dir, '--panel' => 'app', '--force' => true,
    ]);

    expect($code)->toBe(Command::SUCCESS)
        ->and(file_get_contents($app))->toContain('VacuumPlugin::make()');
});

it('lists the panels when several match and it cannot choose', function (): void {
    writeProvider($this->dir, 'AdminPanelProvider');
    writeProvider($this->dir, 'AppPanelProvider');

    $code = Artisan::call('vacuum:install', ['--filament' => true, '--panel-dir' => $this->dir]);

    expect($code)->toBe(Command::SUCCESS)
        ->and(Artisan::output())->toContain('AdminPanelProvider')
        ->and(Artisan::output())->toContain('AppPanelProvider');
});

it('lists the panels when a name matches none of several', function (): void {
    writeProvider($this->dir, 'AdminPanelProvider');
    writeProvider($this->dir, 'AppPanelProvider');

    $code = Artisan::call('vacuum:install', [
        '--filament' => true, '--panel-dir' => $this->dir, '--panel' => 'nowhere', '--force' => true,
    ]);

    expect($code)->toBe(Command::SUCCESS)
        ->and(Artisan::output())->toContain('AdminPanelProvider');
});

it('lets you choose between several panels when asked interactively', function (): void {
    writeProvider($this->dir, 'AdminPanelProvider');
    $app = writeProvider($this->dir, 'AppPanelProvider');

    $this->artisan('vacuum:install', ['--filament' => true, '--panel-dir' => $this->dir, '--force' => true])
        ->expectsChoice('Which panel should Vacuum be added to?', 'AppPanelProvider.php', [
            'AdminPanelProvider.php',
            'AppPanelProvider.php',
        ])
        ->assertExitCode(Command::SUCCESS);

    expect(file_get_contents($app))->toContain('VacuumPlugin::make()');
});

it('chooses filament from a prompt when no flag is given', function (): void {
    writeProvider($this->dir, 'AdminPanelProvider');

    $this->artisan('vacuum:install', ['--panel-dir' => $this->dir, '--force' => true])
        ->expectsChoice('How should Vacuum serve its UI?', 'filament', ['blade', 'filament'])
        ->assertExitCode(Command::SUCCESS);
});
