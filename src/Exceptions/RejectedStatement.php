<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Exceptions;

use RuntimeException;

final class RejectedStatement extends RuntimeException
{
    public static function empty(): self
    {
        return new self('Type a statement to run.');
    }

    public static function notReadOnly(string $opener): self
    {
        return new self("The console reads and never writes, and [{$opener}] is not a statement that reads.");
    }

    public static function tooMany(): self
    {
        return new self('The console runs one statement at a time.');
    }

    /**
     * Named plainly, and with the real answer alongside it: the guard is a courtesy,
     * and the privileges of the connecting role are what actually bound this.
     */
    public static function reachesOutside(string $function): self
    {
        return new self(
            "[{$function}] reaches outside the read-only transaction, so the console will not run it. "
            .'Note that this list is a courtesy and not a boundary: what a console statement can really do '
            .'is decided by the privileges of the role Vacuum connects as. Point vacuum.connection at a '
            .'role that may not do this.'
        );
    }

    public static function analyzeForbidden(): self
    {
        return new self(
            'EXPLAIN ANALYZE actually runs the query, so it is off unless the application turns it on. '
            .'Set vacuum.console.explain_analyze to allow it.'
        );
    }
}
