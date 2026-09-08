-- Every index's key columns, in the order the index stores them.
--
-- This is the shape question rather than the usage question. pg_stat_user_indexes
-- says whether anything has read an index, which a database created ninety seconds
-- ago in a pipeline cannot say anything about at all; pg_index says what the index
-- would be able to serve, which is true the moment CREATE INDEX returns.
--
-- indkey is an int2vector and is 0-based, so the key columns are the slice from 0
-- to indnkeyatts - 1. The columns past that slice are an INCLUDE payload: stored in
-- the leaf, not searchable, and therefore not part of what the index can serve.
--
-- Indexes over expressions are excluded. Their indkey carries a 0 where the
-- expression is, which joins to no pg_attribute row, and a column list with a
-- silent gap in it is worse than no column list: every rule reading this asks
-- whether an index leads with named plain columns, and an expression index is
-- never the answer to that question.
SELECT
    namespaces.nspname AS schemaname,
    tables.relname AS tablename,
    indexes.relname AS indexname,
    (
        SELECT coalesce(string_agg(attributes.attname, ',' ORDER BY keys.ordinality), '')
        FROM unnest((idx.indkey::int2[])[0:idx.indnkeyatts - 1]) WITH ORDINALITY AS keys (attnum, ordinality)
        JOIN pg_attribute AS attributes
          ON attributes.attrelid = idx.indrelid
         AND attributes.attnum = keys.attnum
    ) AS columns,
    idx.indisunique AS isunique,
    idx.indisvalid AS isvalid,
    (idx.indpred IS NOT NULL) AS ispartial,
    methods.amname AS method
FROM pg_index AS idx
JOIN pg_class AS indexes ON indexes.oid = idx.indexrelid
JOIN pg_class AS tables ON tables.oid = idx.indrelid
JOIN pg_namespace AS namespaces ON namespaces.oid = tables.relnamespace
JOIN pg_am AS methods ON methods.oid = indexes.relam
WHERE idx.indexprs IS NULL
  AND tables.relkind IN ('r', 'p')
  AND namespaces.nspname <> ALL (string_to_array(?, ','))
ORDER BY namespaces.nspname, tables.relname, indexes.relname
