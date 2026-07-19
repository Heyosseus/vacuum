-- Every column of every ordinary and partitioned table.
--
-- This is what lets a lesson recognise Laravel in the schema without autoloading
-- a single one of the application's classes: deleted_at is what SoftDeletes
-- adds, created_at and updated_at are what $table->timestamps() adds, and a json
-- or jsonb column is what an Eloquent cast maps onto. Inference, and the prose
-- that uses it says so -- the catalog can prove the column exists and cannot
-- prove which trait put it there.
--
-- attnum > 0 excludes the system columns; NOT attisdropped excludes the ones
-- that have been dropped, which keep their pg_attribute row so that the tuples
-- written before the drop can still be interpreted.
SELECT
    namespaces.nspname AS schemaname,
    tables.relname AS tablename,
    attributes.attname AS columnname,
    format_type(attributes.atttypid, attributes.atttypmod) AS datatype,
    NOT attributes.attnotnull AS nullable
FROM pg_attribute AS attributes
JOIN pg_class AS tables ON tables.oid = attributes.attrelid
JOIN pg_namespace AS namespaces ON namespaces.oid = tables.relnamespace
WHERE tables.relkind IN ('r', 'p')
  AND attributes.attnum > 0
  AND NOT attributes.attisdropped
  AND namespaces.nspname <> ALL (string_to_array(?, ','))
ORDER BY namespaces.nspname, tables.relname, attributes.attnum
