# Changelog

All notable changes to `heyosseus/vacuum` are documented here.

This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html) and the format of [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- **`multixact-wraparound`, the other clock.** PostgreSQL has two independent wraparound counters and the cluster stops when *either* runs out; Vacuum measured only the transaction one. The second counts multixacts — allocated whenever more than one transaction holds a lock on the same row at once, which is ordinary under foreign keys or `SELECT ... FOR UPDATE` — and is advanced by autovacuum on its own schedule against its own horizon. A table under a lock-heavy workload could sit at a healthy `age(relfrozenxid)` and still be the table that stopped the database. New rule, new `mxid_age` on `TableStatistic` and `TableProfile`, new `thresholds.wraparound_mxid_age` (400,000,000, matching `autovacuum_multixact_freeze_max_age`) and `wraparound_mxid_age_critical`. The remediation was already right: `VACUUM (FREEZE, ANALYZE)` advances both horizons.
- **The SQL console records every statement it runs** — the authenticated user's identifier, the statement, the row count, the duration and the connection — to the application's log. On by default (`vacuum.console.audit`), with an optional dedicated channel (`vacuum.console.audit_channel`).
- **The SQL console's POST route is throttled**, configurably (`vacuum.console.rate_limit`, default `30,1`).
- **CI now runs the suite against PostgreSQL 14, 15, 16 and 17.** Every leg previously started `postgres:17`, so for a package whose value is SQL correctness against system catalogs that drift between majors, none of the supported-but-not-current versions was exercised at all.

### Fixed

- **`duplicate-index` no longer calls two indexes with different collations copies of each other.** The "same index" signature omitted `indcollation` — while the file's own comment claimed collation was part of it — so two indexes over one text column under `COLLATE "C"` and the database default got identical signatures. Vacuum then recommended `DROP INDEX CONCURRENTLY` on an index that was the only thing serving that ordering.
- **`pg_stat_statements` rows are aggregated by `queryid`.** The view keys on `(userid, dbid, queryid, toplevel)`, so one statement run by two roles was two rows: the slowest-query list showed it twice, the Filament `Statement` model declared a primary key that was not unique (making a sorted, paged table repeat and skip rows), and the history snapshot's per-queryid metrics collided so `intervalStatementCost` computed its delta from whichever row won. Calls, total time and rows are now summed and the mean recomputed from the sums.
- **The bloat estimate ignores tables whose row count nobody has measured.** Since PostgreSQL 14 `reltuples` is `-1` for a never-analyzed relation — "unknown", not zero — and `TRUNCATE` resets it to `-1` while leaving the `pg_stats` rows behind, so the table sailed past the existing `is_na` guard and into the page arithmetic.
- **The bloat estimate is no longer inflated on ARM64.** MAXALIGN is inferred from the `version()` string, and `aarch64`/`arm64` matched none of the 64-bit alternatives — so every table on Graviton, Apple silicon and most modern ARM cloud was measured against a 4-byte alignment when the truth is 8, understating the padding and overstating the bloat of everything.
- **`cache-hit-ratio`'s drill-down counts index blocks.** The headline ratio comes from `pg_stat_database` and counts every block; the query that named the tables responsible counted only `heap_blks_*`, so on an index-heavy workload it pointed at the wrong tables.
- **`LinearFit` no longer loses precision on unix timestamps.** The normal-equation form computes `n·Σx² − (Σx)²`, which at x ≈ 1.78 billion subtracts two numbers near 4e20 to produce one near 2e10; x is now centred on its mean first, and a known slope through hourly snapshots comes back exact rather than to six figures.
- **A snapshot runs the expensive bloat estimate once**, not twice.
- **The SQL console caps rows at the server rather than in PHP.** `max_rows` was applied to a result that was already whole and already in memory — `statement_timeout` bounds how long a statement runs, not how much it returns — so a `generate_series` that finished instantly could still exhaust the worker. The statement is now wrapped in a subquery with a `LIMIT` of `max_rows + 1`, so the rows past the cap are never produced. A capped result reports that there was more rather than a total it would have to scan the table to learn.
- **The SQL console renders a friendly error instead of a 500** when the connection has a transaction already open (`NestedTransaction`) or is not PostgreSQL (`UnsupportedDriver`).
- **Filament widgets each gate on `Vacuum::check`.** They are registered as global Livewire components, so authorization came only from the page containing them.

### Security

- **The console's documentation now states its guarantee accurately.** "PostgreSQL refuses the write, not this form" was true of MVCC writes from the current backend and not of anything else: `dblink` opens a second backend whose transaction is not read-only, and `pg_read_file`, `pg_ls_dir`, `pg_terminate_backend`, `lo_export` and `lo_import` have their effects outside MVCC entirely. All of them begin with `SELECT`. `README.md`, `SECURITY.md`, `config/vacuum.php` and the console page itself now say that **the boundary is the role Vacuum connects as** — which, with `VACUUM_CONNECTION` unset, is the application's own role — and `SECURITY.md` carries the grants for a minimally-privileged one.
- `StatementGuard` refuses the known escapes above by name. This is a courtesy on the same footing as the existing keyword check, it is described as one everywhere it appears, and it is not the security boundary.

## [0.3.0] - 2026-07-16

### Added

- **History over time (opt-in).** A `vacuum:snapshot` command records the health score, the findings and the raw per-object metrics behind them into three tables on the application's own database, on a schedule you set or that Vacuum registers for you. Once two snapshots exist, the advisor gains **interval-accurate** `cache-hit-ratio` and `slow-statement` figures — measured over the last interval rather than the life of the server — a climbing / easing / new **direction** on each finding, a **time-to-critical forecast** for freeze age and table size, and a diff of what is newly wrong or newly cleared since the previous snapshot. Surfaced as a **History** page in the Filament panel and a **history** tab on the Blade dashboard, both shown only while history is on.
- History is off by default and is the package's only write path; it writes exclusively to the storage connection (`VACUUM_HISTORY_CONNECTION`, the application's default when unset) and never to the inspected database. New configuration under `vacuum.history`, and a published migration (`php artisan vendor:publish --tag=vacuum-migrations`).

### Fixed

- The statements panel and inspection no longer 500 on a server where `CREATE EXTENSION pg_stat_statements` ran but the library was never listed in `shared_preload_libraries`. The capability probe now reads the preload list and treats the extension as usable only when it is both created and loaded; a role the server hides the list from (no `pg_read_all_settings`) keeps the old trust in `pg_extension`, and a mid-request SQLSTATE `55000` degrades to the guidance finding instead of an error page either way.
- One inspection throwing no longer 500s the whole dashboard. The advisor now catches each inspection's failure and reports it as an `inspection-failed` Info finding — naming the inspection, keeping the exception message as the impact — so the panels fed by the other inspections keep rendering.

## [0.1.0] - 2026-07-15

First release.

### Added

- **The advisor.** Twelve rules across tables, indexes, sessions, statements and server settings — wraparound, autovacuum-disabled, dead-tuples, stale-statistics, table-bloat, unused-index, duplicate-index, invalid-index, cache-hit-ratio, idle-in-transaction, blocked-session and slow-statement. Each finding carries a severity, what it costs, and the statement that would fix it, and the findings roll up into a health score and grade computed from the findings themselves.
- **The Blade dashboard** at `/vacuum`, showing the findings, the score, any running vacuums and the server's capabilities.
- **Authorization** through a `Vacuum::auth()` callback that opens in `local` and refuses everywhere else, with its own middleware appended so the dashboard cannot be exposed by emptying the middleware array.
- **`vacuum:check`**, the advisor for a terminal and for CI, exiting non-zero on findings at or above a configurable severity, with a `--format=json` mode.
- **An opt-in SQL console** that runs statements inside a rolled-back `READ ONLY` transaction PostgreSQL itself refuses writes to, with a statement timeout and a row cap.
- **`vacuum:install`**, which publishes the config and wires the UI — Blade, or, on an existing Filament v4 panel, by splicing the plugin into the panel provider through the PHP tokenizer with a backup, a `php -l` check and a printed fallback.
- **A Filament v4 panel** (optional peer — nothing changes for a Blade-only install): a **Vacuum** navigation group with an **Overview** dashboard (health score and grade, database vitals, charts, the findings with copyable remediation, and live running vacuums) and read-only resources for **Tables**, **Indexes**, **Sessions** and **Statements**. Every surface shares the one `Vacuum::auth()` gate and opts out of tenant scoping, so it is at home in a multi-tenant panel.
- **Extensibility.** Application rules can be tagged onto the advisor per subject (`TABLE_RULES`, `INDEX_RULES`, and the rest), and both the config and the dashboard views are publishable.

[Unreleased]: https://github.com/heyosseus/vacuum/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/heyosseus/vacuum/compare/v0.1.0...v0.3.0
[0.1.0]: https://github.com/heyosseus/vacuum/releases/tag/v0.1.0
