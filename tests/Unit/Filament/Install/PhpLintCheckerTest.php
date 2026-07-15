<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Filament\Install\PhpLintChecker;

beforeEach(function (): void {
    $this->file = sys_get_temp_dir().'/vacuum-lint-'.bin2hex((string) getmypid()).'.php';
});

afterEach(function (): void {
    if (is_file($this->file)) {
        unlink($this->file);
    }
});

it('passes a file that parses', function (): void {
    file_put_contents($this->file, "<?php\n\nfunction ok(): int\n{\n    return 1;\n}\n");

    expect((new PhpLintChecker)->check($this->file))->toBeTrue();
});

it('fails a file that does not parse', function (): void {
    // The exact shape a botched edit would leave behind: an unterminated statement.
    // This is the fault the installer restores a backup from.
    file_put_contents($this->file, "<?php\n\nreturn \$panel->default(\n");

    expect((new PhpLintChecker)->check($this->file))->toBeFalse();
});
