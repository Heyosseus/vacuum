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

it('restores the original when its own edit would not parse', function (): void {
    // The whole point of the net: even when the tokenizer places the call, if PHP
    // then rejects the file the change is rolled back and the caller is told, so a
    // developer never opens a broken provider.
    $original = standardProvider();
    file_put_contents($this->file, $original);

    $outcome = installerWith(checker(false))->install($this->file);

    expect($outcome)->toBe(InstallOutcome::SyntaxRestored)
        ->and(file_get_contents($this->file))->toBe($original)
        ->and(is_file($this->file.'.bak'))->toBeFalse();
});
