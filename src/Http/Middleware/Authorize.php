<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Http\Middleware;

use Closure;
use Heyosseus\Vacuum\Vacuum;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The only door into the dashboard. Every Vacuum route is behind it.
 */
final class Authorize
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Vacuum::check($request), 403);

        return $next($request);
    }
}
