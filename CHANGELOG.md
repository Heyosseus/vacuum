# Changelog

All notable changes to `heyosseus/vacuum` are documented here.

This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html) and the format of [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Fixed

- The statements panel and inspection no longer 500 on a server where `CREATE EXTENSION pg_stat_statements` ran but the library was never listed in `shared_preload_libraries`. The capability probe now reads the preload list and treats the extension as usable only when it is both created and loaded; a role the server hides the list from (no `pg_read_all_settings`) keeps the old trust in `pg_extension`, and a mid-request SQLSTATE `55000` degrades to the guidance finding instead of an error page either way.

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

[Unreleased]: https://github.com/heyosseus/vacuum/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/heyosseus/vacuum/releases/tag/v0.1.0
