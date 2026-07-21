# Security Policy

## Supported versions

The latest minor release receives security fixes.

## Reporting a vulnerability

Please do not report security vulnerabilities through public GitHub issues.

Email **ratiruxadzee@gmail.com** with a description of the issue, the steps to reproduce it, and the affected version. You will get an acknowledgement within 72 hours.

## Threat model

Vacuum exposes database internals — table names, row counts, query text, and optionally the results of arbitrary `SELECT` statements — through a web dashboard. Reports that matter most:

- Any way to execute a statement that writes to the **inspected** database, through the SQL console or otherwise. History writes its snapshots to the application's own database over a separate connection; a way to redirect those writes onto the inspected connection, or to write through the read-only inspection path at all, is in scope.
- Any way to reach the dashboard or its JSON endpoints without passing the configured authorization gate.
- Any way to make Vacuum issue a query it did not construct itself (SQL injection through a filter, sort or search parameter).

## What the SQL console does and does not guarantee

The console is off by default. When it is on, read this section rather than assuming.

Every statement runs inside a transaction Vacuum has set `READ ONLY` and always rolls back. **That guarantee is narrower than "PostgreSQL refuses everything harmful," and the difference is not a detail.**

A `READ ONLY` transaction constrains *this backend's* writes to MVCC-managed storage. It says nothing about:

- **A second backend.** `SELECT dblink('dbname=app', 'DELETE FROM users WHERE id = 1')` opens a separate connection with a transaction of its own, which is not read-only. It begins with `SELECT`, contains no top-level semicolon, and writes to your database.
- **Side effects outside MVCC.** `pg_read_file('/etc/passwd')` and `pg_ls_dir('/')` read the server's filesystem. `pg_terminate_backend(pid)` and `pg_cancel_backend(pid)` kill connections. `lo_export(oid, '/path')` writes a file to the server's disk. `nextval('seq')` leaves a persistent change behind. `READ ONLY` permits all of them.

Vacuum's `StatementGuard` turns away the known escapes above, and refuses statements that do not begin with a reading keyword. **This is a courtesy, not a boundary, and it is not offered as one.** A word list has never secured anything: `WITH x AS (INSERT ... RETURNING *) SELECT * FROM x` begins with `WITH`, and a reader with motive will find more. The guard exists so that an accident produces a sentence instead of a stack trace.

**The boundary is the role.** What a console statement can actually do is decided by the privileges of the role `vacuum.connection` resolves to, and by nothing else. With `VACUUM_CONNECTION` unset — the default — that is your application's own role, which frequently owns every table and is sometimes a superuser.

If you enable the console, give it a role of its own:

```sql
CREATE ROLE vacuum_reader LOGIN PASSWORD '...' NOSUPERUSER NOCREATEDB NOCREATEROLE;
GRANT CONNECT ON DATABASE app TO vacuum_reader;
GRANT USAGE ON SCHEMA public TO vacuum_reader;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO vacuum_reader;

-- Vacuum needs this to see other sessions and the statistics views.
GRANT pg_read_all_stats TO vacuum_reader;

-- Revoke the escapes READ ONLY does not cover.
REVOKE EXECUTE ON FUNCTION pg_read_file(text), pg_read_binary_file(text),
    pg_ls_dir(text), pg_terminate_backend(integer), pg_cancel_backend(integer),
    lo_export(oid, text), lo_import(text) FROM PUBLIC, vacuum_reader;

-- If dblink or postgres_fdw are installed at all, revoke them explicitly.
REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA public FROM vacuum_reader;
REVOKE USAGE ON FOREIGN DATA WRAPPER postgres_fdw FROM vacuum_reader;
```

Do not install `dblink` or `postgres_fdw` on a database the console points at unless you need them.

### Other console limits worth knowing

- **Rows and bytes are both capped at the server.** `vacuum.console.max_rows` wraps your statement in a subquery with a `LIMIT`, and `vacuum.console.max_bytes` adds a running total of row widths that the server filters on, so the results beyond either cap are never produced and never sent. The byte budget is what stops a result that is inside the row cap and still enormous — three hundred rows of a megabyte each. The row that crosses the budget is kept, so a truncated result is never silently an empty one, which leaves exactly one case unbounded: `SELECT repeat('x', 500000000)` is a single row with nothing before it, and is bounded only by `statement_timeout` and PHP's `memory_limit`.
- **The statement that is checked is the statement that is run.** The guard returns the normalized SQL and the console executes that, rather than re-deriving it — and the guard's comment stripping knows what a string literal is, so `SELECT '--', pg_read_file('/etc/passwd')` is not read as a bare `SELECT '` with everything after it commented out. This closes a way of hiding a call from the denylist. It does not promote the denylist to a security boundary; see above.
- **Every statement is logged** (`vacuum.console.audit`, on by default): the authenticated user's identifier, the statement, the row count, the duration and the connection.
- **The POST route is throttled** (`vacuum.console.rate_limit`).
- **PostgreSQL's errors are shown verbatim**, which is deliberate — a console that rewrites the database's own words lies about what happened — and means error text may disclose schema detail to anyone the auth gate admits.
