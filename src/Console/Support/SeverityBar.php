<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Console\Support;

use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Severity;

/**
 * The severity at which a command should fail the build.
 *
 * Shared by vacuum:check and vacuum:lint, which set different defaults for the
 * same reason they exist separately: a warning from check is a database
 * drifting, and a warning from lint is a schema that was wrong the moment
 * somebody typed it.
 */
final readonly class SeverityBar
{
    private function __construct(private ?Severity $limit) {}

    /**
     * The bar the caller named, or null if they named something that is not one.
     */
    public static function parse(?string $bar): ?self
    {
        if ($bar === 'never') {
            return new self(null);
        }

        $severity = $bar === null ? null : Severity::tryFrom($bar);

        return $severity instanceof Severity ? new self($severity) : null;
    }

    /**
     * Whether anything found is at or above this bar.
     *
     * An unknown is excluded by rank today, but only by accident of its number.
     * Said out loud, it survives somebody reordering the ranks: a build should go
     * red because the database has a problem, never because the role was short a
     * grant.
     *
     * @param  list<Finding>  $findings
     */
    public function fails(array $findings): bool
    {
        if (! $this->limit instanceof Severity) {
            return false;
        }

        foreach ($findings as $finding) {
            if ($finding->severity !== Severity::Unknown && $finding->severity->rank() <= $this->limit->rank()) {
                return true;
            }
        }

        return false;
    }
}
