<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Exceptions;

use RuntimeException;

final class MissingStatement extends RuntimeException
{
    public static function named(string $name): self
    {
        return new self("Vacuum has no SQL statement named [{$name}].");
    }
}
