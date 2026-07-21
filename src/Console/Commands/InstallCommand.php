<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Console\Commands;

use Heyosseus\Vacuum\Filament\Install\InstallOutcome;
use Heyosseus\Vacuum\Filament\Install\PanelInstaller;
use Heyosseus\Vacuum\Filament\Install\PanelProviderLocator;
use Heyosseus\Vacuum\Filament\Install\PluginRegistrar;
use Illuminate\Console\Command;

/**
 * Sets Vacuum up in an application, and chooses how its UI is served.
 *
 * Blade is the standalone dashboard at /vacuum. Filament hands the same information
 * to a panel plugin instead, and the one delicate thing that entails -- editing a
 * panel provider the developer owns -- is done only after it can be done safely, and
 * never silently: refuse, restore, or print by-hand instructions whenever it cannot.
 */
final class InstallCommand extends Command
{
    protected $signature = 'vacuum:install
        {--blade : Serve the dashboard as the standalone Blade routes}
        {--filament : Serve the UI through a Filament panel plugin}
        {--panel= : Which panel to add Vacuum to, when there is more than one}
        {--panel-dir= : Where the Filament panel providers live}
        {--force : Apply the edit without confirming, for non-interactive use}';

    protected $description = 'Install Vacuum and choose whether it is served through Blade or Filament';

    public function handle(PanelProviderLocator $locator, PanelInstaller $installer): int
    {
        $this->callSilently('vendor:publish', ['--tag' => 'vacuum-config']);

        $mode = $this->mode();

        if ($mode === null) {
            $this->components->error('Choose one UI, not both: pass --blade or --filament.');

            return self::INVALID;
        }

        if ($mode === 'blade') {
            $this->components->info('Vacuum will serve its dashboard at /vacuum.');

            return self::SUCCESS;
        }

        return $this->installFilament($locator, $installer);
    }

    /**
     * Which UI to set up, or null when told to serve both and it cannot. A run that
     * says nothing and cannot be asked keeps today's behaviour: the Blade dashboard.
     */
    private function mode(): ?string
    {
        $blade = (bool) $this->option('blade');
        $filament = (bool) $this->option('filament');

        if ($blade && $filament) {
            return null;
        }

        if ($blade) {
            return 'blade';
        }

        if ($filament) {
            return 'filament';
        }

        if ($this->input->isInteractive()) {
            $choice = $this->choice('How should Vacuum serve its UI?', ['blade', 'filament'], 'blade');

            // choice() widens to array for its multi-select form; this prompt is not one.
            return is_string($choice) ? $choice : 'blade';
        }

        return 'blade';
    }

    private function installFilament(PanelProviderLocator $locator, PanelInstaller $installer): int
    {
        $panels = $locator->all($this->panelDirectory());

        if ($panels === []) {
            $this->noPanel();

            return self::SUCCESS;
        }

        $target = $this->choosePanel($panels);

        if ($target === null) {
            $this->manyPanels($panels);

            return self::SUCCESS;
        }

        if (! $this->confirmedWrite($target)) {
            $this->manual($target);

            return self::SUCCESS;
        }

        $this->report($installer->install($target), $target);

        return self::SUCCESS;
    }

    private function panelDirectory(): string
    {
        $directory = $this->option('panel-dir');

        return is_string($directory) ? $directory : app_path('Providers/Filament');
    }

    /**
     * The one panel to edit: the only one there is, the one named on the command
     * line, or the one chosen at a prompt. Null when the choice cannot be made, so
     * the caller lists them rather than guessing.
     *
     * @param  list<string>  $panels
     */
    private function choosePanel(array $panels): ?string
    {
        if (count($panels) === 1) {
            return $panels[0];
        }

        $named = $this->option('panel');

        if (is_string($named) && $named !== '') {
            $matches = array_values(array_filter(
                $panels,
                static fn (string $path): bool => str_contains(strtolower(basename($path)), strtolower($named)),
            ));

            return count($matches) === 1 ? $matches[0] : null;
        }

        if ($this->input->isInteractive()) {
            $choice = $this->choice(
                'Which panel should Vacuum be added to?',
                array_map(basename(...), $panels),
            );

            foreach ($panels as $path) {
                if (is_string($choice) && basename($path) === $choice) {
                    return $path;
                }
            }
        }

        return null;
    }

    /**
     * Whether the developer has agreed to the edit. --force says yes outright; an
     * interactive run asks; a run that can neither be forced nor asked declines, so
     * nothing is ever written to a file behind somebody's back.
     */
    private function confirmedWrite(string $target): bool
    {
        if ((bool) $this->option('force')) {
            return true;
        }

        if ($this->input->isInteractive()) {
            return $this->confirm('Add Vacuum to '.basename($target).'?', true);
        }

        return false;
    }

    private function report(InstallOutcome $outcome, string $target): void
    {
        $file = basename($target);

        match ($outcome) {
            InstallOutcome::Wired => $this->wired($file),
            InstallOutcome::AlreadyRegistered => $this->components->info("Vacuum is already registered in {$file}."),
            InstallOutcome::Unrecognised => $this->couldNotParse($file, $target),
            InstallOutcome::SyntaxRejected => $this->rejected($file, $target),
            InstallOutcome::Failed => $this->failed($file, $target),
        };
    }

    private function wired(string $file): void
    {
        $this->components->info("Vacuum's plugin was added to {$file}.");
        $this->components->warn('Only the Tables screen exists so far; the Dashboard and Console follow in later releases.');
        $this->envHint();
    }

    private function couldNotParse(string $file, string $target): void
    {
        $this->components->warn("Vacuum could not read the shape of {$file}, so it changed nothing.");
        $this->manual($target);
    }

    private function rejected(string $file, string $target): void
    {
        $this->components->warn("The edit to {$file} would not have parsed, so it was never written. Add Vacuum by hand:");
        $this->manual($target);
    }

    private function failed(string $file, string $target): void
    {
        $this->components->warn(
            "Vacuum could not write to {$file} -- check its permissions and the free space on the volume. "
            .'Nothing was changed. Add Vacuum by hand:'
        );
        $this->manual($target);
    }

    private function noPanel(): void
    {
        $this->components->warn('No Filament panel was found in '.$this->panelDirectory().'.');
        $this->line('  Create one with <options=bold>php artisan filament:install --panels</>, then run this again,');
        $this->line('  or add Vacuum to a panel yourself:');
        $this->line('    <fg=gray>'.PluginRegistrar::PLUGIN_CALL.'</>');
        $this->newLine();
    }

    /**
     * @param  list<string>  $panels
     */
    private function manyPanels(array $panels): void
    {
        $this->components->warn('Vacuum found more than one panel and could not tell which to use:');

        foreach ($panels as $path) {
            $this->line('    '.basename($path));
        }

        $this->newLine();
        $this->line('  Re-run with <options=bold>--panel=<name></> to choose, or add Vacuum by hand:');
        $this->line('    <fg=gray>'.PluginRegistrar::PLUGIN_CALL.'</>');
        $this->newLine();
    }

    private function manual(string $target): void
    {
        $this->newLine();
        $this->line('  Add this inside the panel() chain in <options=bold>'.$target.'</>:');
        $this->line('    <fg=gray>'.PluginRegistrar::PLUGIN_CALL.'</>');
        $this->newLine();
        $this->envHint();
    }

    private function envHint(): void
    {
        $this->line('  Set <options=bold>VACUUM_UI=filament</> in your .env to switch off the standalone Blade routes.');
        $this->newLine();
    }
}
