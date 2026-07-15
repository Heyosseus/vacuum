<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Filament\Models\Concerns;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;

/**
 * What every one of Vacuum's Eloquent windows shares.
 *
 * Each model is a window and not a door: it is bound to a PostgreSQL statistics view,
 * so it reads the catalog and nothing more. save() and delete() throw rather than reach
 * the connection, which is the whole of Vacuum's safety claim made structural -- there
 * is no code path through these models that writes to the inspected database. The
 * connection they read is Vacuum's configured one, not the application's default, so a
 * panel inspects the same database everything else in the package does.
 */
trait ReadsSystemCatalog
{
    /**
     * Read down Vacuum's configured connection, not the application's default, so the
     * panel inspects the same database everything else in the package does.
     */
    public function getConnectionName(): ?string
    {
        $name = app(Repository::class)->get('vacuum.connection');

        return is_string($name) ? $name : null;
    }

    /**
     * @param  array<string, mixed>  $options
     *
     * @codeCoverageIgnore The point of the model is that this never runs; the test that
     *                     proves it throws does not count as covering the body.
     */
    public function save(array $options = []): bool
    {
        throw new RuntimeException('Vacuum never writes to the database it inspects; its catalog views are read-only.');
    }

    /**
     * @codeCoverageIgnore As above: a model that cannot be deleted is the safety net,
     *                     and the guard body is not meaningful to line-count.
     */
    public function delete(): bool
    {
        throw new RuntimeException('Vacuum never writes to the database it inspects; its catalog views are read-only.');
    }
}
