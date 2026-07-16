<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Console;

use Heyosseus\Vacuum\Exceptions\RejectedStatement;
use Illuminate\Contracts\Config\Repository;

/**
 * Turns away statements that plainly do not belong in a read-only console.
 *
 * This is a courtesy, not a defence, and the distinction matters enough to write
 * down. A word list cannot secure anything: "WITH x AS (INSERT ... RETURNING *)
 * SELECT * FROM x" begins with WITH and writes to your database, and a determined
 * reader will find more. What actually stops the write is that the statement runs
 * inside a transaction PostgreSQL has been told is READ ONLY, and PostgreSQL
 * enforces that itself. The guard exists so that somebody who types DELETE by
 * accident gets a sentence instead of a stack trace.
 *
 * The denylist below is the same kind of courtesy, and needs the same warning even
 * more loudly, because the statements on it are ones READ ONLY does *not* stop. A
 * read-only transaction constrains this backend's writes through MVCC. It has
 * nothing to say about a function that opens a second backend, which then has a
 * transaction of its own, or about effects that happen outside MVCC altogether —
 * reading a file, killing a connection, writing a large object to disk. All of them
 * begin with SELECT and sail past every other check here.
 *
 * So they are named and refused. But the only thing that actually bounds them is
 * the privileges of the role the console connects as: a role that cannot execute
 * pg_read_file cannot be argued into it, and a role that can is not saved by this
 * list. Vacuum's real answer to those is in the config file, and it is "connect as
 * a role that may not do them".
 */
final readonly class StatementGuard
{
    /**
     * @var list<string>
     */
    private const array READS = ['SELECT', 'WITH', 'EXPLAIN', 'SHOW', 'TABLE', 'VALUES'];

    /**
     * Functions that reach past the read-only transaction. Not a security boundary
     * — see the class docblock — but each one is a thing no console statement has a
     * legitimate reason to be doing.
     *
     * @var list<string>
     */
    private const array ESCAPES = [
        // Opens a second backend, with a second transaction that is not read-only.
        'dblink',
        'dblink_connect',
        'dblink_connect_u',
        'dblink_exec',
        'dblink_open',
        'dblink_fetch',
        'dblink_send_query',

        // Effects outside MVCC, which is all READ ONLY constrains.
        'pg_read_file',
        'pg_read_binary_file',
        'pg_ls_dir',
        'pg_terminate_backend',
        'pg_cancel_backend',
        'lo_export',
        'lo_import',
    ];

    public function __construct(private Repository $config) {}

    /**
     * @throws RejectedStatement
     */
    public function check(string $statement): void
    {
        $sql = $this->readable($statement);

        if ($sql === '') {
            throw RejectedStatement::empty();
        }

        if (str_contains($sql, ';')) {
            throw RejectedStatement::tooMany();
        }

        $opener = strtoupper((string) preg_replace('/\W.*$/s', '', $sql));

        if (! in_array($opener, self::READS, true)) {
            throw RejectedStatement::notReadOnly($opener);
        }

        if ($this->isAnalyze($sql) && ! $this->analyzeAllowed()) {
            throw RejectedStatement::analyzeForbidden();
        }

        $escape = $this->escape($sql);

        if ($escape !== null) {
            throw RejectedStatement::reachesOutside($escape);
        }
    }

    /**
     * The first denylisted function the statement calls, if it calls one.
     *
     * Matched as a call — the name, then optional whitespace, then an opening
     * parenthesis — and bounded at the front so it is a whole name rather than a
     * substring. A column called dblink_status is somebody's ordinary schema and
     * not an attempt at anything, and a guard that cannot tell the difference is a
     * guard people switch off.
     */
    private function escape(string $sql): ?string
    {
        foreach (self::ESCAPES as $function) {
            if (preg_match('/(?<![a-z0-9_])'.preg_quote($function, '/').'\s*\(/i', $sql) === 1) {
                return $function;
            }
        }

        return null;
    }

    /**
     * The statement as PostgreSQL would read it: comments are not instructions, and
     * a DELETE hiding behind "-- SELECT 1" is the oldest trick there is.
     */
    private function readable(string $statement): string
    {
        $sql = (string) preg_replace('/--[^\n]*/', ' ', $statement);
        $sql = (string) preg_replace('#/\*.*?\*/#s', ' ', $sql);

        // Everybody types the trailing semicolon; nobody means a second statement by it.
        return trim(rtrim(trim($sql), ';'));
    }

    private function isAnalyze(string $sql): bool
    {
        return preg_match('/^EXPLAIN\b.*\bANALYZE\b/is', $sql) === 1;
    }

    private function analyzeAllowed(): bool
    {
        return (bool) $this->config->get('vacuum.console.explain_analyze', false);
    }
}
