<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Severity;

it('sorts an unknown below everything it might be a caveat on', function (): void {
    expect(Severity::Unknown->rank())->toBeGreaterThan(Severity::Info->rank());
});

it('charges nothing for a check that could not run', function (): void {
    // An unknown is the absence of evidence. Deducting for it would punish a
    // reader for the privileges their role was granted, and inventing a score
    // from data nobody could read is the lie this severity exists to prevent.
    expect(Severity::Unknown->weight())->toBe(0);
});
