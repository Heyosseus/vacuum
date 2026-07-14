<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Exceptions;

use RuntimeException;

final class NestedTransaction extends RuntimeException
{
    public static function onConnection(string $connection): self
    {
        return new self(
            "Vacuum cannot inspect the [{$connection}] connection while a transaction is already open on it: "
            .'a savepoint cannot be made read-only, so the read-only guarantee would not hold.'
        );
    }
}
