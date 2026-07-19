<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Internals;

/**
 * Whether an explorer can actually run against the connected server, and if
 * not, why.
 *
 * A panel that renders empty is indistinguishable from a database with
 * nothing to show. Every unavailable explorer must be able to say which
 * extension is missing, which grant would fix it, and — where the reader is
 * on managed PostgreSQL that forbids it outright — that no grant will.
 */
final readonly class Availability
{
    private function __construct(
        public bool $available,
        public ?string $reason,
        public ?string $remedy,
    ) {}

    public static function available(): self
    {
        return new self(true, null, null);
    }

    public static function missingExtension(string $extension): self
    {
        return new self(
            false,
            "The {$extension} extension is not installed on this server.",
            "CREATE EXTENSION {$extension};",
        );
    }

    public static function insufficientPrivilege(string $grant): self
    {
        return new self(
            false,
            "The connected role does not carry the {$grant} privilege this explorer needs.",
            "GRANT {$grant} TO current_user;",
        );
    }

    public static function disabled(): self
    {
        return new self(
            false,
            'The internals explorers are disabled for this application.',
            null,
        );
    }
}
