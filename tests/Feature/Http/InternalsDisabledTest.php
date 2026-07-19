<?php

declare(strict_types=1);

/**
 * The internals route is registered while routes are being built, gated on
 * the same config the {@see Heyosseus\Vacuum\Http\Controllers\HistoryController}
 * route already uses that pattern for -- so proving it is absent by default
 * needs the plain, unconfigured application this file's default TestCase
 * boots, rather than a test that flips the switch too late to matter.
 */
it('is not routable at all when internals are switched off', function (): void {
    expect(fn (): mixed => route('vacuum.internals'))->toThrow(Exception::class);
});
