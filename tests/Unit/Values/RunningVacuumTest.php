<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Values\RunningVacuum;

function running(int $total, int $scanned): RunningVacuum
{
    return new RunningVacuum(
        pid: 4242,
        schema: 'public',
        table: 'pallets',
        phase: 'scanning heap',
        blocksTotal: $total,
        blocksScanned: $scanned,
        indexPasses: 0,
        startedAt: null,
        automatic: false,
    );
}

it('measures how far through the heap it is', function (): void {
    expect(running(total: 800, scanned: 200)->percentScanned())->toBe(25.0);
});

it('refuses to invent progress for a phase that counts no blocks', function (): void {
    // PostgreSQL reports zero total blocks in the phases where it is not scanning
    // the heap. A progress bar that shows a number there is a progress bar lying.
    expect(running(total: 0, scanned: 0)->percentScanned())->toBeNull();
});
