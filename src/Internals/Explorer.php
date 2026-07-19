<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Internals;

/**
 * A read of a subject the reader picked, not a judgement.
 *
 * Explorers deliberately do not implement Inspection or Rule: they produce
 * views of a page, a tuple, a chain — not findings with a severity attached.
 * The decision of whether what they show is a problem is left to the reader
 * looking at it.
 */
interface Explorer
{
    /**
     * Whether this explorer can run against the connected server right now,
     * and if not, what is missing and how to fix it.
     */
    public function availability(): Availability;
}
