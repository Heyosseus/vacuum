# Contributing

Thanks for considering a contribution.

## Getting set up

Vacuum reads PostgreSQL's own statistics views, so the test suite needs a real PostgreSQL instance — SQLite cannot stand in for it.

```bash
docker compose up -d
composer install
composer test
```

## Before you open a pull request

`composer test` runs the whole gate, and CI runs exactly the same thing:

| Command | What it checks |
| --- | --- |
| `composer test:refacto` | Rector finds nothing left to modernize |
| `composer test:lint` | Pint formatting |
| `composer test:types` | PHPStan at max level |
| `composer test:type-coverage` | Every parameter, property and return is typed |
| `composer test:unit` | Pest, with 100% line coverage |

`composer lint` and `composer refacto` fix the first two for you.

## Adding an advisor rule

An advisor rule is a small class that answers one question about the database and, if the answer is bad, explains why and offers the SQL that would fix it. A rule must never execute a statement that modifies anything — it returns SQL as a string for a human to read and decide on.

## Reporting security issues

Do not open a public issue. See [SECURITY.md](SECURITY.md).
