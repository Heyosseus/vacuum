# Changelog

All notable changes to `heyosseus/vacuum` are documented here.

This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html) and the format of [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

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
