<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Exceptions;

use LogicException;

/**
 * The registered lessons do not form a curriculum.
 *
 * Both causes are a lesson class being wrong about its own place -- a
 * prerequisite that was renamed, or two lessons each claiming to build on the
 * other. Neither can be caused by a request, and neither should be survivable:
 * a curriculum that cannot be drawn is a bug to fix before release, not a
 * degraded page to serve.
 */
final class InvalidCurriculum extends LogicException
{
    public static function unknownPrerequisite(string $lesson, string $after): self
    {
        return new self("The lesson [{$lesson}] builds on [{$after}], which no registered lesson claims.");
    }

    public static function cycle(string $lesson): self
    {
        return new self("The lesson [{$lesson}] builds on itself, directly or through others.");
    }
}
