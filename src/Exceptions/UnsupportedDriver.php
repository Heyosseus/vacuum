<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Exceptions;

use RuntimeException;

final class UnsupportedDriver extends RuntimeException
{
    public static function for(string $connection, string $driver): self
    {
        return new self(
            "Vacuum inspects PostgreSQL only, but the [{$connection}] connection uses the [{$driver}] driver."
        );
    }
}
