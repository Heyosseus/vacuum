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
    | is wrapped in. The 'vacuum' middleware alias resolves to an authorization
    | gate; see the Gate section below.
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
        'cache_hit_ratio' => 0.99,
        'bloat_bytes' => 100 * 1024 * 1024,
        'unused_index_min_size' => 1024 * 1024,
        'long_running_query_seconds' => 60,
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
