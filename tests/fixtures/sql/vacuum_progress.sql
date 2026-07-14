-- A row shaped exactly the way pg_stat_progress_vacuum shapes one.
--
-- A vacuum over a test table finishes in milliseconds, and no test can reliably
-- catch one in flight without making the suite depend on timing. So the mapping is
-- tested against a row PostgreSQL itself builds, with the columns and the types
-- the real view has, and the real view is tested separately for the one thing this
-- cannot show: that it can be asked at all.
--
-- The binding is consumed so the query is called exactly as the real one is.
SELECT
    4242 AS pid,
    'public' AS schemaname,
    'pallets' AS relname,
    'vacuuming indexes' AS phase,
    1000 AS heap_blks_total,
    250 AS heap_blks_scanned,
    2 AS index_vacuum_count,
    now() - interval '2 minutes' AS started_at,
    true AS automatic
WHERE ?::text IS NOT NULL
