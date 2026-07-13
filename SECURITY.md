# Security Policy

## Supported versions

The latest minor release receives security fixes.

## Reporting a vulnerability

Please do not report security vulnerabilities through public GitHub issues.

Email **ratiruxadzee@gmail.com** with a description of the issue, the steps to reproduce it, and the affected version. You will get an acknowledgement within 72 hours.

## Threat model

Vacuum exposes database internals — table names, row counts, query text, and optionally the results of arbitrary `SELECT` statements — through a web dashboard. Reports that matter most:

- Any way to execute a statement that writes, through the SQL console or otherwise.
- Any way to reach the dashboard or its JSON endpoints without passing the configured authorization gate.
- Any way to make Vacuum issue a query it did not construct itself (SQL injection through a filter, sort or search parameter).
