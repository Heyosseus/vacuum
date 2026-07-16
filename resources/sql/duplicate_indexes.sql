-- Indexes that are an exact copy of another index on the same table.
--
-- Two indexes are the same index when they cover the same table, the same
-- columns in the same order, with the same operator classes, the same collation
-- and sort options, the same expression and the same partial predicate. That
-- whole list is what indkey, indclass, indcollation, indoption, indexprs and
-- indpred hold, so it is what the signature is built from. Comparing the text of
-- pg_get_indexdef would be easier and wrong: it differs on whitespace and agrees
-- on things that are not equal.
--
-- indcollation earns its place: two indexes over one text column under different
-- collations agree on every other part of the signature, and sort in different
-- orders. PostgreSQL will only use each for the collation it was built for, so
-- they are not copies of each other however identical the rest of them looks.
--
-- This deliberately does not report an index whose columns are merely a prefix of
-- another's. An index on (a) is redundant against (a, b) for lookups on a, but it
-- is smaller, and there are workloads where keeping both is right. Telling you to
-- drop something that might be earning its keep is how a tool loses your trust.
--
-- Of each group of identical indexes one is kept: the primary key if there is one,
-- then a unique index, then the smallest, then whichever sorts first — so the
-- answer is the same every time it is asked. The rest are what this returns.
WITH indexed AS (
    SELECT
        namespaces.nspname AS schemaname,
        tables.relname AS tablename,
        indexes.relname AS indexname,
        pg_relation_size(indexes.oid) AS index_bytes,
        pg_get_indexdef(indexes.oid) AS definition,
        catalog.indisunique OR catalog.indisprimary AS constrains,
        catalog.indrelid::text
            ||' '|| catalog.indkey::text
            ||' '|| catalog.indclass::text
            ||' '|| catalog.indcollation::text
            ||' '|| catalog.indoption::text
            ||' '|| coalesce(pg_get_expr(catalog.indexprs, catalog.indrelid), '')
            ||' '|| coalesce(pg_get_expr(catalog.indpred, catalog.indrelid), '') AS signature,
        catalog.indisprimary,
        catalog.indisunique
    FROM pg_index AS catalog
    JOIN pg_class AS indexes ON indexes.oid = catalog.indexrelid
    JOIN pg_class AS tables ON tables.oid = catalog.indrelid
    JOIN pg_namespace AS namespaces ON namespaces.oid = tables.relnamespace
    WHERE catalog.indisvalid
      AND namespaces.nspname <> ALL (string_to_array(?, ','))
),
ranked AS (
    SELECT
        indexed.*,
        first_value(indexname) OVER (
            PARTITION BY signature
            ORDER BY indisprimary DESC, indisunique DESC, index_bytes, indexname
        ) AS keeper
    FROM indexed
)
SELECT schemaname, tablename, indexname, index_bytes, definition, constrains, keeper
FROM ranked
WHERE indexname <> keeper
ORDER BY index_bytes DESC, indexname
