<?php

declare(strict_types=1);

/**
 * Switching Learn off must remove it from the router, not merely hide its links:
 * a section that still answers on its URL has not been switched off.
 */
it('is not routable when the section is switched off', function (): void {
    expect(fn (): mixed => route('vacuum.learn'))->toThrow(Exception::class);

    $this->get('/vacuum/learn')->assertNotFound();
});
