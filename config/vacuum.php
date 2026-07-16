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
    | User Interface
    |--------------------------------------------------------------------------
    |
    | How Vacuum serves its dashboard. 'blade' registers the standalone routes
    | above. 'filament' hands the UI to the Filament plugin instead and registers
    | no standalone routes, so the same information is never reachable by two
    | different doors. Run `php artisan vacuum:install` to choose.
    |
    */

    'ui' => env('VACUUM_UI', 'blade'),

    /*
    |--------------------------------------------------------------------------
    | SQL Console
    |--------------------------------------------------------------------------
    |
    | The console executes statements inside a READ ONLY transaction that is
    | always rolled back. It is disabled by default: enabling it lets an
    | authorized user read every row in your database, which is a decision that
    | should be made deliberately.
    |
    | Read this part carefully, because the guarantee is narrower than it looks.
    | A READ ONLY transaction constrains *this* backend's writes to MVCC storage.
    | It does not constrain a function that opens a second connection, and it does
    | not constrain side effects that happen outside MVCC. All of these begin with
    | SELECT and none of them are stopped by the transaction:
    |
    |     SELECT dblink('dbname=app', 'DELETE FROM users');  -- a second backend
    |     SELECT pg_read_file('/etc/passwd');                -- reads the disk
    |     SELECT pg_terminate_backend(pid);                  -- kills connections
    |     SELECT lo_export(oid, '/tmp/anywhere');            -- writes a file
    |
    | The thing that actually stops those is the *role*. The console's power is
    | bounded by the privileges of whatever role 'connection' above resolves to,
    | and by nothing else. Point it at a dedicated role that owns nothing, and
    | revoke EXECUTE on the escapes:
    |
    |     CREATE ROLE vacuum_reader LOGIN PASSWORD '...' NOSUPERUSER;
    |     GRANT pg_read_all_stats TO vacuum_reader;
    |     REVOKE EXECUTE ON FUNCTION pg_read_file(text), pg_ls_dir(text),
    |         pg_terminate_backend(int), pg_cancel_backend(int),
    |         lo_export(oid, text), lo_import(text) FROM PUBLIC, vacuum_reader;
    |     -- and, if the extensions exist at all:
    |     REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA public FROM vacuum_reader;
    |
    | With VACUUM_CONNECTION unset, the console runs as your application's own
    | role -- frequently the owner of everything, sometimes a superuser. Vacuum's
    | own statement guard turns away the obvious cases as a courtesy, but a word
    | list is not a security boundary and it is not offered as one.
    |
    | 'timeout' is the per-statement timeout in milliseconds (statement_timeout).
    | 'max_rows' caps the rows, and is asked of PostgreSQL rather than applied to
    | the answer: the console wraps your statement so the rows past the cap are
    | never produced. It bounds the number of rows and not the width of one, so a
    | single enormous value is still bounded only by 'timeout' and memory_limit.
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

        // Every statement the console runs is written to the log: who ran it, what
        // it was, how many rows it returned and how long it took. A console that
        // can read every row in production and records nothing about who read them
        // is a gap somebody eventually has to answer for. Null uses the
        // application's default channel.
        'audit' => env('VACUUM_CONSOLE_AUDIT', true),
        'audit_channel' => env('VACUUM_CONSOLE_AUDIT_CHANNEL'),

        // Laravel throttle notation: attempts,minutes. The console is an
        // authenticated surface, so this is not a login defence -- it is a bound on
        // how fast one authorized reader can drive expensive queries at the server
        // it is pointed at.
        'rate_limit' => env('VACUUM_CONSOLE_RATE_LIMIT', '30,1'),
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

        // Rows written since the last analyze, as a share of the rows the table
        // holds. Autoanalyze fires at 0.1, so 0.2 is the point at which it is
        // being outrun rather than merely working. The minimums keep the rule off
        // tables too small or too quiet for a bad row estimate to cost anything.
        'stale_statistics_ratio' => 0.20,
        'stale_statistics_minimum' => 10_000,
        'stale_statistics_minimum_rows' => 1_000,

        // Transactions a table may fall behind the present before Vacuum says so.
        // PostgreSQL's own autovacuum_freeze_max_age defaults to 200 million, the
        // age at which it freezes a table whether or not anything has written to
        // it; raise this to match if you have raised that. The critical age is
        // roughly half of the 2.1 billion the server can count before it refuses
        // to accept another write.
        'wraparound_xid_age' => 200_000_000,
        'wraparound_xid_age_critical' => 1_000_000_000,

        // The same question asked of the other wraparound clock. PostgreSQL
        // allocates a multixact when more than one transaction holds a lock on the
        // same row at once, numbers them from a separate 32-bit counter, and stops
        // the cluster if that counter runs out — independently of the transaction
        // one above. The warning default is 400 million because that is
        // autovacuum_multixact_freeze_max_age's own default: twice the transaction
        // horizon, so this is deliberately not the same number as
        // 'wraparound_xid_age'. Raise it to match if you have raised that setting.
        'wraparound_mxid_age' => 400_000_000,
        'wraparound_mxid_age_critical' => 1_000_000_000,

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

    /*
    |--------------------------------------------------------------------------
    | History
    |--------------------------------------------------------------------------
    |
    | Vacuum is point-in-time by default: every page load and every check runs
    | the advisor fresh and reports the database as it is this instant. History
    | records a snapshot on a schedule so the package can tell you which way a
    | number is moving — bloat that is growing, an xid age that climbs and never
    | resets, a cache-hit ratio measured over the last hour rather than over the
    | life of the server.
    |
    | This is the package's only write path, and it never touches the inspected
    | database: snapshots are written with ordinary Eloquent to the connection
    | below — your application's own database — while the inspected server is
    | still only ever read, read-only, through the same path everything else uses.
    |
    | Off by default. The write path, the schema and the schedule all come into
    | being only once you turn it on. Publish and run the migration with:
    |
    |     php artisan vendor:publish --tag=vacuum-migrations
    |     php artisan migrate
    |
    */

    'history' => [
        'enabled' => env('VACUUM_HISTORY_ENABLED', false),

        // Where snapshots are stored. Null means the application's default
        // connection. This is the write connection; it is never the database
        // Vacuum inspects unless you deliberately point it at the same one.
        'connection' => env('VACUUM_HISTORY_CONNECTION'),

        // Snapshots older than this are pruned each time a new one is taken, so
        // the tables do not grow without bound.
        'retention_days' => env('VACUUM_HISTORY_RETENTION_DAYS', 90),

        // The cadence Vacuum registers the snapshot command at, when you let it
        // schedule itself. Hourly is fine enough for the interval metrics and
        // cheap enough to leave running. Set to null to schedule it yourself.
        'schedule' => env('VACUUM_HISTORY_SCHEDULE', 'hourly'),

        // Only tables at least this large are trended, which keeps the metrics
        // table bounded on a database that has thousands of small ones.
        'metric_table_min_bytes' => env('VACUUM_HISTORY_METRIC_TABLE_MIN_BYTES', 10 * 1024 * 1024),

        'forecast' => [
            // A projection needs enough points behind it to be worth trusting.
            // Below this many snapshots, Vacuum forecasts nothing rather than
            // draw a line through three dots and call it the future.
            'minimum_snapshots' => 12,
        ],
    ],

];
