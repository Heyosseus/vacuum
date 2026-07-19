-- Primary keys, unique constraints and foreign keys, and whether an index
-- actually covers each one.
--
-- PostgreSQL creates an index for a primary key and for a unique constraint. It
-- creates none for a foreign key. MySQL does, which is why a schema that was
-- fast there is slow here: every delete or key update on the parent has to check
-- the child, and with no index that check is a sequential scan of the child
-- table holding a lock for the duration.
--
-- Covered means the constraint's columns are the LEADING columns of some index,
-- in order. An index on (status, customer_id) does not serve a foreign key on
-- customer_id; one on (customer_id, status) does. That is why this compares a
-- prefix of indkey rather than testing set membership -- the set is identical in
-- both cases and only one of them is usable.
--
-- indkey is an int2vector and is 0-based; conkey is an int2[] and is 1-based.
-- Array equality in PostgreSQL compares contents and element counts and not
-- subscript bounds, so slicing indkey from 0 to n-1 and comparing it to conkey
-- is correct, and the off-by-one it looks like it has, it does not.
SELECT
    namespaces.nspname AS schemaname,
    tables.relname AS tablename,
    constraints.conname AS constraintname,
    constraints.contype::text AS kind,
    (
        SELECT coalesce(string_agg(attributes.attname, ',' ORDER BY keys.ordinality), '')
        FROM unnest(constraints.conkey) WITH ORDINALITY AS keys (attnum, ordinality)
        JOIN pg_attribute AS attributes
          ON attributes.attrelid = constraints.conrelid
         AND attributes.attnum = keys.attnum
    ) AS columns,
    coalesce(referenced.relname, '') AS referencedtable,
    EXISTS (
        SELECT 1
        FROM pg_index AS indexes
        WHERE indexes.indrelid = constraints.conrelid
          AND indexes.indisvalid
          AND (indexes.indkey::int2[])[0:cardinality(constraints.conkey) - 1] = constraints.conkey
    ) AS indexed
FROM pg_constraint AS constraints
JOIN pg_class AS tables ON tables.oid = constraints.conrelid
JOIN pg_namespace AS namespaces ON namespaces.oid = tables.relnamespace
LEFT JOIN pg_class AS referenced ON referenced.oid = constraints.confrelid
WHERE constraints.contype IN ('p', 'u', 'f')
  AND namespaces.nspname <> ALL (string_to_array(?, ','))
ORDER BY namespaces.nspname, tables.relname, constraints.conname
