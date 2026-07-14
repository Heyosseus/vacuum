-- How each table has been written to, when it was last cleaned up, and how far
-- its oldest unfrozen row has fallen behind the present.
--
-- The freeze age is the reason this reaches past pg_stat_user_tables into
-- pg_class: relfrozenxid is the oldest transaction id the table still holds a
-- row from, and age() measures it against the present. Autovacuum normally
-- keeps it under autovacuum_freeze_max_age. A table whose age climbs past that
-- and keeps climbing is one nothing is freezing, and at the far end of that
-- road PostgreSQL stops accepting writes.
--
-- The one binding is a comma separated list of schemas to ignore. A file cannot
-- grow a placeholder per schema the way an assembled string can, so PostgreSQL
-- splits the list itself and the binding count stays at one.
SELECT
    s.schemaname,
    s.relname,
    s.n_live_tup,
    s.n_dead_tup,
    s.n_mod_since_analyze,
    age(c.relfrozenxid) AS xid_age,
    s.last_vacuum,
    s.last_autovacuum,
    s.last_analyze,
    s.last_autoanalyze
FROM pg_stat_user_tables s
JOIN pg_class c ON c.oid = s.relid
WHERE s.schemaname <> ALL (string_to_array(?, ','))
ORDER BY s.n_dead_tup DESC, s.relname
