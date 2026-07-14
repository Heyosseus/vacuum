-- Estimates how much of each table's on-disk size is space it is holding on to
-- rather than space it is using.
--
-- PostgreSQL will not tell you a table's bloat: knowing it exactly means reading
-- every page (pgstattuple), which on a large table is the expensive scan you are
-- trying to avoid. So this reconstructs the size the table *ought* to be from the
-- planner's own statistics -- the column widths and null fractions in pg_stats,
-- the row count in pg_class -- and calls the difference bloat.
--
-- It is an estimate, and it is derived from ioguix's, which is the estimate the
-- PostgreSQL community has been refining for a decade. Where the statistics are
-- missing or cannot be trusted, the row marks itself is_na and is dropped rather
-- than reported as a confident zero.
--
-- Bindings: 1. a comma-separated list of schemas to leave out.
SELECT
    schemaname,
    tblname,
    fillfactor,
    bs * tblpages AS real_size,
    CASE
        WHEN tblpages - est_tblpages_ff > 0 THEN (tblpages - est_tblpages_ff) * bs
        ELSE 0
    END AS bloat_size
FROM (
    SELECT
        ceil(reltuples / ((bs - page_hdr) * fillfactor / (tpl_size * 100))) + ceil(toasttuples / 4) AS est_tblpages_ff,
        tblpages,
        fillfactor,
        bs,
        schemaname,
        tblname,
        is_na
    FROM (
        SELECT
            (
                4 + tpl_hdr_size + tpl_data_size + (2 * ma)
                - CASE WHEN tpl_hdr_size % ma = 0 THEN ma ELSE tpl_hdr_size % ma END
                - CASE WHEN ceil(tpl_data_size)::int % ma = 0 THEN ma ELSE ceil(tpl_data_size)::int % ma END
            ) AS tpl_size,
            heappages + toastpages AS tblpages,
            reltuples,
            toasttuples,
            bs,
            page_hdr,
            schemaname,
            tblname,
            fillfactor,
            is_na
        FROM (
            SELECT
                ns.nspname AS schemaname,
                tbl.relname AS tblname,
                tbl.reltuples,
                tbl.relpages AS heappages,
                coalesce(toast.relpages, 0) AS toastpages,
                coalesce(toast.reltuples, 0) AS toasttuples,
                coalesce(
                    substring(array_to_string(tbl.reloptions, ' ') FROM 'fillfactor=([0-9]+)')::smallint,
                    100
                ) AS fillfactor,
                current_setting('block_size')::numeric AS bs,
                CASE WHEN version() ~ 'mingw32' OR version() ~ '64-bit|x86_64|ppc64|ia64|amd64' THEN 8 ELSE 4 END AS ma,
                24 AS page_hdr,
                23
                    + CASE WHEN max(coalesce(s.null_frac, 0)) > 0 THEN (7 + count(s.attname)) / 8 ELSE 0::int END
                    + CASE WHEN bool_or(att.attname = 'oid' AND att.attnum < 0) THEN 4 ELSE 0 END AS tpl_hdr_size,
                sum((1 - coalesce(s.null_frac, 0)) * coalesce(s.avg_width, 0)) AS tpl_data_size,
                bool_or(att.atttypid = 'pg_catalog.name'::regtype)
                    OR sum(CASE WHEN att.attnum > 0 THEN 1 ELSE 0 END) <> count(s.attname) AS is_na
            FROM pg_attribute AS att
            JOIN pg_class AS tbl ON att.attrelid = tbl.oid
            JOIN pg_namespace AS ns ON ns.oid = tbl.relnamespace
            LEFT JOIN pg_stats AS s
                ON s.schemaname = ns.nspname
                AND s.tablename = tbl.relname
                AND s.inherited = false
                AND s.attname = att.attname
            LEFT JOIN pg_class AS toast ON tbl.reltoastrelid = toast.oid
            WHERE NOT att.attisdropped
                AND tbl.relkind IN ('r', 'm')
                AND ns.nspname <> ALL (string_to_array(?, ','))
            GROUP BY ns.nspname, tbl.relname, tbl.reltuples, tbl.relpages, toast.relpages, toast.reltuples, tbl.reloptions
        ) AS columns
    ) AS rows
) AS tables
WHERE NOT is_na
ORDER BY bloat_size DESC, schemaname, tblname
