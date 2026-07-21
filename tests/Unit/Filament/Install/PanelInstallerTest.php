<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Filament\Install\InstallOutcome;
use Heyosseus\Vacuum\Filament\Install\PanelInstaller;
use Heyosseus\Vacuum\Filament\Install\PluginRegistrar;
use Heyosseus\Vacuum\Filament\Install\SyntaxChecker;

/** A syntax checker whose verdict the test decides, so both paths are reachable. */
function checker(bool $verdict): SyntaxChecker
{
    return new readonly class($verdict) implements SyntaxChecker
    {
        public function __construct(private bool $verdict) {}

        public function check(string $file): bool
        {
            return $this->verdict;
        }
    };
}

function installerWith(SyntaxChecker $syntax): PanelInstaller
{
    return new PanelInstaller(new PluginRegistrar, $syntax);
}

function standardProvider(): string
{
    return <<<'PHP'
    <?php

    namespace App\Providers\Filament;

    use Filament\Panel;
    use Filament\PanelProvider;

    class AdminPanelProvider extends PanelProvider
    {
        public function panel(Panel $panel): Panel
        {
            return $panel
                ->default()
                ->id('admin');
        }
    }
    PHP;
}

beforeEach(function (): void {
    $this->file = sys_get_temp_dir().'/vacuum-installer-'.bin2hex((string) getmypid()).'.php';
});

afterEach(function (): void {
    foreach ([$this->file, $this->file.'.bak'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('wires the plugin in and leaves no backup behind when the result parses', function (): void {
    file_put_contents($this->file, standardProvider());

    $outcome = installerWith(checker(true))->install($this->file);

    expect($outcome)->toBe(InstallOutcome::Wired)
        ->and(file_get_contents($this->file))->toContain('VacuumPlugin::make()')
        ->and(is_file($this->file.'.bak'))->toBeFalse();
});

it('reports a provider that already has the plugin without touching it', function (): void {
    $already = str_replace(
        "->id('admin');",
        "->id('admin')\n            ->plugin(\Heyosseus\Vacuum\Filament\VacuumPlugin::make());",
        standardProvider(),
    );
    file_put_contents($this->file, $already);

    $outcome = installerWith(checker(true))->install($this->file);

    expect($outcome)->toBe(InstallOutcome::AlreadyRegistered)
        ->and(file_get_contents($this->file))->toBe($already);
});

it('will not edit a shape it cannot parse', function (): void {
    file_put_contents($this->file, "<?php\n\nclass AdminPanelProvider {}\n");

    $outcome = installerWith(checker(true))->install($this->file);

    expect($outcome)->toBe(InstallOutcome::Unrecognised)
        ->and(file_get_contents($this->file))->toBe("<?php\n\nclass AdminPanelProvider {}\n");
});

it('never writes an edit that would not parse, and leaves nothing behind', function (): void {
    // The whole point of the net, and it is now a net that cannot itself tear:
    // the edit is written to a temporary file, PHP rejects that, and the provider
    // is never touched at all. There is no rollback because there was nothing to
    // roll back -- which is the only version of this that cannot report a
    // restoration it did not perform.
    $original = standardProvider();
    file_put_contents($this->file, $original);

    $outcome = installerWith(checker(false))->install($this->file);

    expect($outcome)->toBe(InstallOutcome::SyntaxRejected)
        ->and(file_get_contents($this->file))->toBe($original)
        ->and(is_file($this->file.'.bak'))->toBeFalse()
        ->and(is_file($this->file.'.vacuum-pending'))->toBeFalse();
});

it('reports failure rather than success when it cannot read the provider at all', function (): void {
    // The bug this replaces reported Wired for a file it never changed, and sent
    // the reader off to set VACUUM_UI=filament for a panel with no plugin in it.
    expect(installerWith(checker(true))->install($this->file.'.does-not-exist'))
        ->toBe(InstallOutcome::Failed);
});

it('reports failure when the edit cannot be written beside the provider', function (): void {
    // A directory sitting where the temporary file wants to be is the portable
    // way to make the write fail. The point is that it *is* reported: every
    // filesystem call here used to discard its return value, so a write that
    // never happened was followed by a lint of the untouched original, which of
    // course passed, and the command announced the panel wired.
    file_put_contents($this->file, standardProvider());
    mkdir($this->file.'.vacuum-pending');

    try {
        expect(installerWith(checker(true))->install($this->file))->toBe(InstallOutcome::Failed)
            ->and(file_get_contents($this->file))->toBe(standardProvider());
    } finally {
        rmdir($this->file.'.vacuum-pending');
    }
});

it('reports failure when the checked file cannot be moved into place', function (): void {
    // The checker is handed the temporary file; this one deletes it, which is the
    // deterministic stand-in for the thing that really happens -- a full disk, a
    // permission change, an interrupt between the lint and the move. Whatever the
    // cause, the provider is untouched and the caller is told so.
    file_put_contents($this->file, standardProvider());

    $vanishing = new readonly class implements SyntaxChecker
    {
        public function check(string $file): bool
        {
            unlink($file);

            return true;
        }
    };

    expect(installerWith($vanishing)->install($this->file))->toBe(InstallOutcome::Failed)
        ->and(file_get_contents($this->file))->toBe(standardProvider());
});
