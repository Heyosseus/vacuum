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

    public static function analyzeForbidden(): self
    {
        return new self(
            'EXPLAIN ANALYZE actually runs the query, so it is off unless the application turns it on. '
            .'Set vacuum.console.explain_analyze to allow it.'
        );
    }
}
