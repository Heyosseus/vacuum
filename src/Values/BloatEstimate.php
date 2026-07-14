<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Values;

/**
 * How much of a table's size is space it is holding on to rather than using.
 *
 * An estimate, and it says so in its name. The exact figure costs a full scan of
 * every page, which is the cost this whole package exists to help you avoid.
 */
final readonly class BloatEstimate
{
    public function __construct(
        public string $schema,
        public string $name,
        public int $fillfactor,
        public int $realBytes,
        public int $bloatBytes,
    ) {}

    public function qualifiedName(): string
    {
        return $this->schema.'.'.$this->name;
    }

    public function bloatRatio(): float
    {
        if ($this->realBytes === 0) {
            return 0.0;
        }

        return $this->bloatBytes / $this->realBytes;
    }
}
