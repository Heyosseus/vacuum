<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Vacuum Master Switch
    |--------------------------------------------------------------------------
    |
    | When disabled, Vacuum registers no routes and runs no queries. Keep this
    | tied to an environment variable so the dashboard can be switched off in
    | production without a deploy.
    |
    */

    'enabled' => env('VACUUM_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    |
    | The connection Vacuum inspects. Null means the application's default
    | connection. Point this at a dedicated, read-only PostgreSQL role: Vacuum
    | never needs write access, and giving it none is the cheapest safety net
    | you will ever configure.
    |
    */

    'connection' => env('VACUUM_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Dashboard Route
    |--------------------------------------------------------------------------
    |
    | The URI prefix the dashboard is served from, and the middleware stack it
    | is wrapped in. Vacuum appends its own authorization middleware to whatever
    | you list here, so the dashboard cannot be exposed by emptying this array.
    |
    | Authorization itself is a callback, registered from a service provider:
    |
    |     Vacuum::auth(fn (Request $request) => $request->user()?->isAdmin());
    |
    | Register nothing and Vacuum opens in local and refuses everywhere else. A
    | forgotten configuration should lock the door, not leave it open.
    |
    */

    'path' => env('VACUUM_PATH', 'vacuum'),

    'domain' => env('VACUUM_DOMAIN'),

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | SQL Console
    |--------------------------------------------------------------------------
    |
    | The console executes statements inside a READ ONLY transaction that is
    | always rolled back, so PostgreSQL itself rejects any write. It is still
    | disabled by default: enabling it lets an authorized user read every row
    | in your database, which is a decision that should be made deliberately.
    |
    | 'timeout' is the per-statement timeout in milliseconds (statement_timeout).
    | 'max_rows' caps the rows returned to the browser.
    |
    */

    'console' => [
        'enabled' => env('VACUUM_CONSOLE_ENABLED', false),
        'timeout' => env('VACUUM_CONSOLE_TIMEOUT', 5_000),
        'max_rows' => env('VACUUM_CONSOLE_MAX_ROWS', 500),

        // EXPLAIN ANALYZE actually runs the query it is explaining. The read-only
        // transaction still stops it writing, but nothing stops it reading a
        // billion rows, so it is off until you say otherwise.
        'explain_analyze' => env('VACUUM_CONSOLE_EXPLAIN_ANALYZE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Advisor Thresholds
    |--------------------------------------------------------------------------
    |
    | The values the advisor rules compare against when deciding whether a table
    | or index is unhealthy. Defaults are conservative; tune them to your fleet.
    |
    */

    'thresholds' => [
        'dead_tuple_ratio' => 0.20,
        'dead_tuple_minimum' => 1_000,
        'cache_hit_ratio' => 0.99,

        // A database that has served a hundred blocks since it started can have
        // any hit ratio at all, and none of them mean anything.
        'cache_hit_minimum_blocks' => 100_000,

        'bloat_bytes' => 100 * 1024 * 1024,
        'unused_index_min_size' => 1024 * 1024,
        'long_running_query_seconds' => 60,
        'slow_query_milliseconds' => 500,
        'idle_in_transaction_seconds' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Ignored Schemas
    |--------------------------------------------------------------------------
    |
    | Schemas excluded from every panel. PostgreSQL's own catalogs are noisy and
    | not something you can act on.
    |
    */

    'ignored_schemas' => ['pg_catalog', 'information_schema', 'pg_toast'],

];
