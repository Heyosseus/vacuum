<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\History\Models\Concerns;

/**
 * Binds a history model to the connection history is stored on.
 *
 * Null in config means the application's default connection, which is what
 * returning null here selects. This connection is written to; it is deliberately
 * separate from `vacuum.connection`, the database Vacuum only ever reads.
 */
trait StoresHistory
{
    public function getConnectionName(): ?string
    {
        $connection = config('vacuum.history.connection');

        return is_string($connection) && $connection !== '' ? $connection : parent::getConnectionName();
    }
}
