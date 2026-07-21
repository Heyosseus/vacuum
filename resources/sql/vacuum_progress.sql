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
-- The datid filter is not optional. pg_stat_progress_vacuum reports every
-- vacuuming backend in the *cluster* -- that is why it carries datid and datname
-- at all -- while pg_class is per-database. CREATE DATABASE ... TEMPLATE copies
-- pg_class including its OIDs, so two databases hold different relations under
-- the same numbers: without this filter an autovacuum of another database's
-- orders table resolves against this database's catalog and is reported, with
-- complete confidence, as a vacuum of a table that is not being vacuumed.
--
-- pg_class is joined loosely for the same reason pg_stat_activity is. A relation
-- dropped while its vacuum was still running leaves a row here with nothing to
-- resolve, and a vacuum with no name is a better answer than a running vacuum
-- that silently vanishes from the panel. The schema filter has to tolerate the
-- null that comes with it.
SELECT
    progress.pid,
    coalesce(namespaces.nspname, '?') AS schemaname,
    coalesce(tables.relname, '(unknown relation)') AS relname,
    progress.phase,
    progress.heap_blks_total,
    progress.heap_blks_scanned,
    progress.index_vacuum_count,
    activity.query_start AS started_at,
    activity.backend_type = 'autovacuum worker' AS automatic
FROM pg_stat_progress_vacuum AS progress
LEFT JOIN pg_class AS tables ON tables.oid = progress.relid
LEFT JOIN pg_namespace AS namespaces ON namespaces.oid = tables.relnamespace
LEFT JOIN pg_stat_activity AS activity ON activity.pid = progress.pid
WHERE progress.datid = (SELECT oid FROM pg_database WHERE datname = current_database())
  AND (namespaces.nspname IS NULL OR namespaces.nspname <> ALL (string_to_array(?, ',')))
ORDER BY activity.query_start, coalesce(tables.relname, '(unknown relation)')
