<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum;

use Closure;
use Illuminate\Http\Request;

/**
 * Decides who may see the dashboard.
 *
 * Deliberately not a Laravel Gate. A gate callback is skipped entirely for a
 * guest unless its first parameter is nullable, so `Gate::define('viewVacuum',
 * fn ($user) => true)` -- which is what everyone writes -- silently denies a
 * developer who is not logged in, on their own laptop, where the dashboard is
 * most useful. A callback that receives the request has no such trap, and it
 * costs the package no dependency on the auth system, which matters because a
 * dashboard that only reads pg_stat_* has no business requiring one.
 */
final class Vacuum
{
    /**
     * @var (Closure(Request): bool)|null
     */
    private static ?Closure $authUsing = null;

    /**
     * Authorize dashboard requests with the given callback. Pass null to restore
     * the default, which opens the dashboard in local and nowhere else.
     *
     * @param  (Closure(Request): bool)|null  $callback
     */
    public static function auth(?Closure $callback): void
    {
        self::$authUsing = $callback;
    }

    public static function check(Request $request): bool
    {
        $callback = self::$authUsing ?? static fn (Request $request): bool => app()->environment('local');

        return $callback($request);
    }
}
