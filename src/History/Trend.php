<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\History;

/**
 * Which way a number has been moving across recent snapshots.
 *
 * The point of history: a bloat figure inside its threshold but climbing is a
 * different thing to read than the same figure holding steady, and this is the
 * word for the difference.
 */
enum Trend: string
{
    case Rising = 'rising';
    case Falling = 'falling';
    case Flat = 'flat';

    /** No prior snapshot to compare against, so nothing can be said about movement. */
    case Unknown = 'unknown';
}
