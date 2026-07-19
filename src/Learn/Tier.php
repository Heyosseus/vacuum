<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Learn;

/**
 * The six sections a lesson belongs to, in the order the curriculum teaches
 * them: what a table is before what a page is, what a page is before what
 * autovacuum does to it. A lesson names its tier; nothing else decides where
 * it appears.
 */
enum Tier: string
{
    case Eloquent = 'eloquent';
    case Foundations = 'foundations';
    case Storage = 'storage';
    case Indexes = 'indexes';
    case Maintenance = 'maintenance';
    case Advanced = 'advanced';

    /**
     * The heading a tier's panel is shown under.
     */
    public function label(): string
    {
        return match ($this) {
            self::Eloquent => 'Eloquent & Laravel',
            self::Foundations => 'Foundations',
            self::Storage => 'Storage & MVCC',
            self::Indexes => 'Indexes',
            self::Maintenance => 'Maintenance',
            self::Advanced => 'Advanced',
        };
    }

    /**
     * Where a tier sits in the reading order, not the alphabet: a reader
     * learns what a row is before they learn what happens when it dies.
     * Eloquent goes first of all, ahead of Foundations, because that is
     * where the reader actually is: fluent in `$model->update()` and
     * unaware a heap exists. Their own ORM is the on-ramp, not an
     * afterthought bolted onto the end.
     */
    public function order(): int
    {
        return match ($this) {
            self::Eloquent => 0,
            self::Foundations => 1,
            self::Storage => 2,
            self::Indexes => 3,
            self::Maintenance => 4,
            self::Advanced => 5,
        };
    }
}
