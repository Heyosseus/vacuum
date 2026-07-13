# Vacuum

**A PostgreSQL analytics, monitoring and tuning dashboard for Laravel.**

Vacuum reads what PostgreSQL already knows about itself — `pg_stat_user_tables`, `pg_stat_user_indexes`, `pg_statio_*`, `pg_stat_activity`, `pg_class.reloptions` — and turns it into a dashboard that tells you what is wrong and what to do about it.

> **Status: in development.** Nothing below is released yet.

## What it shows

- **Bloat & vacuum health** — dead tuples per table, live/dead ratio, last autovacuum and autoanalyze, tables overdue for a vacuum, per-table `fillfactor` and autovacuum storage parameters.
- **Index health** — indexes that have never been scanned, redundant and duplicate indexes, index size against table size, tables doing sequential scans that shouldn't be.
- **Cache, buffers & I/O** — heap and index cache hit ratios, `shared_buffers` against database size, temp file spills that mean `work_mem` is too low.
- **Connections & activity** — active, idle and idle-in-transaction sessions, the longest-running query, the lock tree behind a blocked query, transaction age against wraparound.
- **Suggestions** — every panel's findings passed through an advisor that explains the problem, the impact, and the exact SQL that would fix it. Vacuum shows you the SQL. It never runs it.
- **SQL console** *(opt-in)* — run `SELECT` and `EXPLAIN` against your database from the browser, inside a `READ ONLY` transaction with a statement timeout that is always rolled back.

## Requirements

- PHP 8.2+
- Laravel 11 or 12
- PostgreSQL 13+

## Installation

```bash
composer require heyosseus/vacuum
```

Publish the configuration:

```bash
php artisan vendor:publish --tag=vacuum-config
```

Then visit `/vacuum`.

## Safety

Vacuum is a read-only tool by construction. It issues `SELECT` statements against PostgreSQL's statistics views and nothing else. The optional SQL console runs inside a read-only transaction, so PostgreSQL itself — not a keyword filter — rejects any attempt to write.

Point `VACUUM_CONNECTION` at a role with no write privileges and the tool cannot damage your database even if it is compromised.

## Development

Vacuum tests against a real PostgreSQL instance, because the statistics views it reads cannot be simulated:

```bash
docker compose up -d
composer install
composer test
```

## License

MIT. See [LICENSE.md](LICENSE.md).
