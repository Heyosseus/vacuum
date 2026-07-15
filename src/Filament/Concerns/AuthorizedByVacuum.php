<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Filament\Concerns;

use Heyosseus\Vacuum\Vacuum;
use Illuminate\Database\Eloquent\Model;

/**
 * The one gate, shared by every Vacuum surface: a resource is reachable exactly when the
 * dashboard is, because every door -- the navigation, the list, a record -- asks the same
 * Vacuum::auth callback rather than a policy the package has no business requiring.
 *
 * Each resource still sets `$navigationGroup = 'Vacuum'` and `$isScopedToTenant = false`
 * in its own body: PHP forbids a trait from redeclaring a property the parent already
 * defines with a different default, and Filament's Resource defines both. The tenancy
 * opt-out matters because Vacuum reads the server's own catalogs, which belong to the
 * cluster and not to any one tenant, so a multi-tenant panel must not try to scope them
 * through an ownership relationship these read-only models have no reason to carry.
 */
trait AuthorizedByVacuum
{
    public static function canAccess(): bool
    {
        return Vacuum::check(request());
    }

    public static function canViewAny(): bool
    {
        return Vacuum::check(request());
    }

    public static function canView(Model $record): bool
    {
        return Vacuum::check(request());
    }
}
