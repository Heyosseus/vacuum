<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Rules\IoTimingOff;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Values\Settings;

it('notes that I/O timing columns are reading a silent zero', function (): void {
    $finding = (new IoTimingOff)->inspect(new Settings([
        'track_io_timing' => setting('track_io_timing', 'off'),
    ]));

    expect($finding)->not->toBeNull()
        ->and($finding->rule)->toBe('io-timing-off')
        ->and($finding->severity)->toBe(Severity::Info)
        ->and($finding->subject)->toBe('server');
});

it('is content when I/O timing is switched on', function (): void {
    expect((new IoTimingOff)->inspect(new Settings([
        'track_io_timing' => setting('track_io_timing', 'on'),
    ])))->toBeNull();
});
