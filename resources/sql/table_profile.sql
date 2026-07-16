-- Everything PostgreSQL knows about one table, in one round trip.
--
-- The sizes are asked for separately rather than added up, because they are four
-- different questions: the heap is the rows, the indexes are what it costs to find
-- them, and TOAST is where the values too large for a page went to live. A table
-- that looks enormous is often a table with one text column and a TOAST relation
-- nobody has ever looked at.
--
-- The autovacuum settings come back with the table because they are the answer to
-- the question everybody actually has: not "what is the scale factor" but "how many
-- dead rows before anything happens". A per-table reloption overrides the server
-- default, so both are needed to work that out, and PostgreSQL is asked for its own
-- rather than being told what the defaults ought to be.
SELECT
    stats.schemaname,
    stats.relname,

    stats.n_live_tup,
    stats.n_dead_tup,
    stats.n_mod_since_analyze,

    stats.seq_scan,
    stats.seq_tup_read,
    stats.idx_scan,
    stats.idx_tup_fetch,

    stats.n_tup_ins,
    stats.n_tup_upd,
    stats.n_tup_hot_upd,
    stats.n_tup_del,

    stats.last_vacuum,
    stats.last_autovacuum,
    stats.last_analyze,
    stats.last_autoanalyze,

    -- Both wraparound clocks, because they run independently: relfrozenxid is
    -- advanced by freezing rows, relminmxid by freezing the row locks that several
    -- transactions held at once, and a table can be current on one and far behind
    -- on the other.
    age(tables.relfrozenxid) AS xid_age,
    mxid_age(tables.relminmxid) AS mxid_age,

    pg_relation_size(tables.oid) AS heap_bytes,
    pg_indexes_size(tables.oid) AS index_bytes,
    coalesce(pg_total_relation_size(tables.reltoastrelid), 0) AS toast_bytes,
    pg_total_relation_size(tables.oid) AS total_bytes,

    array_to_string(tables.reloptions, ',') AS reloptions,

    current_setting('autovacuum_vacuum_scale_factor') AS vacuum_scale_factor,
    current_setting('autovacuum_vacuum_threshold') AS vacuum_threshold,
    current_setting('autovacuum_analyze_scale_factor') AS analyze_scale_factor,
    current_setting('autovacuum_analyze_threshold') AS analyze_threshold
FROM pg_stat_user_tables AS stats
JOIN pg_class AS tables ON tables.oid = stats.relid
WHERE stats.schemaname = ?
  AND stats.relname = ?
