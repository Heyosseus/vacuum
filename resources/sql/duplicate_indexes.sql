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
-- Four more parts earn theirs for the same reason -- pg_index alone does not hold
-- everything that makes two indexes different:
--
--   indnkeyatts       an INCLUDE payload is invisible in indkey's leading columns,
--                     so (a) INCLUDE (b) and (a) would otherwise look identical.
--   conexclop         the operators of an exclusion constraint live in
--                     pg_constraint, not here. Two exclusion constraints on one
--                     column, one WITH = and one WITH &&, have byte-identical
--                     indkey, indclass, indcollation, indoption, indexprs and
--                     indpred, and enforce entirely different rules.
--   NULLS NOT DISTINCT  a unique index that treats nulls as equal is strictly
--                     stricter than one that does not. Called copies, the keeper
--                     tiebreak could drop the stricter of the two and silently
--                     take an integrity guarantee with it.
--
-- The last is read out of pg_get_indexdef rather than from indnullsnotdistinct,
-- which is PostgreSQL 15 and newer while this package supports 14. The clause
-- appears in the definition text exactly when the feature is in use, and on 14 it
-- can never appear because there is nothing there to print -- so one file is
-- correct on every supported major, where a version-gated column would need four
-- otherwise identical variants of this query.
--
-- This deliberately does not report an index whose columns are merely a prefix of
-- another's. An index on (a) is redundant against (a, b) for lookups on a, but it
-- is smaller, and there are workloads where keeping both is right. Telling you to
-- drop something that might be earning its keep is how a tool loses your trust.
--
-- indisunique is deliberately *not* part of the signature. A unique index and an
-- ordinary one over the same column really are copies for every lookup either can
-- serve, and the ordinary one really is redundant — the keeper ordering below sorts
-- unique ahead of plain, so the one that is also enforcing a rule is always the one
-- kept and the advice is to drop the other. Adding indisunique here would separate
-- them and lose a genuine, safe finding. The integrity risk that looks like this
-- one is a pair of *unique* indexes differing only in how they treat nulls, and
-- that is what the NULLS NOT DISTINCT component below is for.
--
-- Of each group of identical indexes one is kept: the primary key if there is one,
-- then a unique index, then the smallest, then whichever sorts first — so the
-- answer is the same every time it is asked. The rest are what this returns.
--
-- constrains says whether PostgreSQL would even allow the drop. A primary key or
-- unique index is the obvious case, but any index a constraint depends on is
-- refused (exclusion constraints back an index that is neither unique nor
-- primary), as is the replica identity, as is a partition child, which can only
-- be dropped through its parent. Advising DROP INDEX on any of them produces an
-- error in the reader's hands, which is the one thing a remediation must not do.
WITH indexed AS (
    SELECT
        namespaces.nspname AS schemaname,
        tables.relname AS tablename,
        indexes.relname AS indexname,
        pg_relation_size(indexes.oid) AS index_bytes,
        pg_get_indexdef(indexes.oid) AS definition,
        catalog.indisunique
            OR catalog.indisprimary
            OR catalog.indisreplident
            OR indexes.relispartition
            OR EXISTS (
                SELECT 1
                FROM pg_depend AS dependencies
                WHERE dependencies.classid = 'pg_class'::regclass
                  AND dependencies.objid = catalog.indexrelid
                  AND dependencies.refclassid = 'pg_constraint'::regclass
                  AND dependencies.deptype = 'i'
            ) AS constrains,
        catalog.indrelid::text
            ||' '|| catalog.indkey::text
            ||' '|| catalog.indclass::text
            ||' '|| catalog.indcollation::text
            ||' '|| catalog.indoption::text
            ||' '|| catalog.indnkeyatts::text
            ||' '|| coalesce(pg_get_expr(catalog.indexprs, catalog.indrelid), '')
            ||' '|| coalesce(pg_get_expr(catalog.indpred, catalog.indrelid), '')
            ||' '|| coalesce((
                SELECT constraints.conexclop::text
                FROM pg_constraint AS constraints
                WHERE constraints.conindid = catalog.indexrelid
                ORDER BY constraints.oid
                LIMIT 1
            ), '')
            ||' '|| (pg_get_indexdef(indexes.oid) LIKE '%NULLS NOT DISTINCT%')::text AS signature,
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
