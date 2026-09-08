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
--
-- indpred IS NULL is part of "covered" too: a partial index holds only the rows
-- its predicate admits, and the referential-integrity check this answers for --
-- does some row in the parent exist -- has to be able to see an arbitrary row,
-- not just the ones a WHERE clause let in. Values\TableSchema::hasIndexLeadingWith()
-- excludes a partial index for the same reason; this column has to agree with it,
-- or the same table can be told both that it is covered and that it is not.
--
-- The types on both sides of a foreign key are carried because a mismatch between
-- them is invisible everywhere else. PostgreSQL accepts a foreign key from an
-- integer to a bigint without complaint, creates the constraint, enforces it
-- correctly -- and the planner then cannot use the parent's index for the check,
-- because the comparison is across types. Nothing in the catalog is marked wrong.
-- The only symptom is that a delete on the parent is slow forever.
--
-- confkey is null for a primary key or a unique constraint, so it is coalesced to
-- an empty array: a constraint that references nothing has no referenced types,
-- which is different from having failed to look them up.
--
-- Every list below is joined with a newline rather than a comma. format_type
-- renders a parameterised type with a comma already inside it -- numeric(10,2) --
-- so a comma-joined list of column types splits that single type into two
-- pieces, which is a real shape in this package's own migrations. Neither
-- format_type's output nor a PostgreSQL identifier can contain a newline, so it
-- is a safe delimiter where a comma is not. All three lists change together
-- because Constraints::toConstraint() zips them by position, and the delimiter
-- never leaves that class.
SELECT
    namespaces.nspname AS schemaname,
    tables.relname AS tablename,
    constraints.conname AS constraintname,
    constraints.contype::text AS kind,
    (
        SELECT coalesce(string_agg(attributes.attname, E'\n' ORDER BY keys.ordinality), '')
        FROM unnest(constraints.conkey) WITH ORDINALITY AS keys (attnum, ordinality)
        JOIN pg_attribute AS attributes
          ON attributes.attrelid = constraints.conrelid
         AND attributes.attnum = keys.attnum
    ) AS columns,
    (
        SELECT coalesce(string_agg(format_type(attributes.atttypid, attributes.atttypmod), E'\n' ORDER BY keys.ordinality), '')
        FROM unnest(constraints.conkey) WITH ORDINALITY AS keys (attnum, ordinality)
        JOIN pg_attribute AS attributes
          ON attributes.attrelid = constraints.conrelid
         AND attributes.attnum = keys.attnum
    ) AS columntypes,
    (
        SELECT coalesce(string_agg(format_type(attributes.atttypid, attributes.atttypmod), E'\n' ORDER BY keys.ordinality), '')
        FROM unnest(coalesce(constraints.confkey, '{}'::int2[])) WITH ORDINALITY AS keys (attnum, ordinality)
        JOIN pg_attribute AS attributes
          ON attributes.attrelid = constraints.confrelid
         AND attributes.attnum = keys.attnum
    ) AS referencedcolumntypes,
    coalesce(referenced.relname, '') AS referencedtable,
    EXISTS (
        SELECT 1
        FROM pg_index AS indexes
        WHERE indexes.indrelid = constraints.conrelid
          AND indexes.indisvalid
          AND indexes.indpred IS NULL
          AND (indexes.indkey::int2[])[0:cardinality(constraints.conkey) - 1] = constraints.conkey
    ) AS indexed
FROM pg_constraint AS constraints
JOIN pg_class AS tables ON tables.oid = constraints.conrelid
JOIN pg_namespace AS namespaces ON namespaces.oid = tables.relnamespace
LEFT JOIN pg_class AS referenced ON referenced.oid = constraints.confrelid
WHERE constraints.contype IN ('p', 'u', 'f')
  AND namespaces.nspname <> ALL (string_to_array(?, ','))
ORDER BY namespaces.nspname, tables.relname, constraints.conname
