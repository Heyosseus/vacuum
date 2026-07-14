-- The vacuums running right now, and how far through they are.
--
-- Nothing here is a complaint. A vacuum in progress is the database doing exactly
-- what it should, and the reason to show it is that the other panels make more
-- sense when you can see it: a table with ten million dead tuples is a different
-- conversation when something is already three quarters of the way through
-- reclaiming them.
--
-- Only the columns every supported version has. max_dead_tuples became
-- dead_tuple_bytes in 17, and a panel that breaks on a version upgrade is worse
-- than a panel with one column fewer.
--
-- pg_stat_activity is joined loosely on purpose: a role without pg_read_all_stats
-- sees no row there for a vacuum somebody else started, and the honest answer is a
-- vacuum with no start time rather than no vacuum at all.
SELECT
    progress.pid,
    namespaces.nspname AS schemaname,
    tables.relname,
    progress.phase,
    progress.heap_blks_total,
    progress.heap_blks_scanned,
    progress.index_vacuum_count,
    activity.query_start AS started_at,
    activity.backend_type = 'autovacuum worker' AS automatic
FROM pg_stat_progress_vacuum AS progress
JOIN pg_class AS tables ON tables.oid = progress.relid
JOIN pg_namespace AS namespaces ON namespaces.oid = tables.relnamespace
LEFT JOIN pg_stat_activity AS activity ON activity.pid = progress.pid
WHERE namespaces.nspname <> ALL (string_to_array(?, ','))
ORDER BY activity.query_start, tables.relname
