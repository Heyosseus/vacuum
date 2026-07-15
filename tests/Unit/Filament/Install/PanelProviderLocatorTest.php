<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Filament\Install\PanelProviderLocator;

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir().'/vacuum-panels-'.bin2hex((string) getmypid());

    if (! is_dir($this->directory)) {
        mkdir($this->directory, 0o777, true);
    }

    foreach (glob($this->directory.'/*') ?: [] as $file) {
        unlink($file);
    }
});

afterEach(function (): void {
    foreach (glob($this->directory.'/*') ?: [] as $file) {
        unlink($file);
    }

    if (is_dir($this->directory)) {
        rmdir($this->directory);
    }
});

it('finds nothing in a directory that is not there', function (): void {
    // A brand-new app has no app/Providers/Filament at all until Filament is
    // installed, and that absence is an answer, not an error.
    expect((new PanelProviderLocator)->all($this->directory.'/missing'))->toBe([]);
});

it('finds nothing when the directory holds no panel providers', function (): void {
    touch($this->directory.'/AppServiceProvider.php');

    expect((new PanelProviderLocator)->all($this->directory))->toBe([]);
});

it('finds the panel providers and leaves everything else alone', function (): void {
    touch($this->directory.'/AdminPanelProvider.php');
    touch($this->directory.'/AppPanelProvider.php');
    touch($this->directory.'/SomeOtherClass.php');

    $found = (new PanelProviderLocator)->all($this->directory);

    // Sorted, so "one panel" and "several panels" are decided the same way every run.
    expect($found)->toBe([
        $this->directory.'/AdminPanelProvider.php',
        $this->directory.'/AppPanelProvider.php',
    ]);
});
