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
     * The statement, normalized, once it has passed every check here.
     *
     * It returns the string rather than nothing so that the caller executes the
     * *same* text this examined. Checking one string and running another is how a
     * guard comes to admit `SELECT '--', pg_read_file('/etc/passwd')`: the copy
     * being inspected had everything after the literal stripped out of it as a
     * comment, and the copy being executed did not. Whatever this method decided
     * about is what PostgreSQL is then given.
     *
     * @throws RejectedStatement
     */
    public function check(string $statement): string
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

        return $sql;
    }

    /**
     * The first denylisted function the statement calls, if it calls one.
     *
     * Matched as a call — the name, optionally quoted, then optional whitespace,
     * then an opening parenthesis — and bounded at the front so it is a whole name
     * rather than a substring. A column called dblink_status is somebody's ordinary
     * schema and not an attempt at anything, and a guard that cannot tell the
     * difference is a guard people switch off.
     *
     * The optional quotes are not decoration. PostgreSQL is perfectly happy with
     * SELECT "pg_read_file"('/etc/passwd'), which is the same call with a quoted
     * identifier, and a pattern that demands the parenthesis immediately after the
     * bare name does not see it at all.
     */
    private function escape(string $sql): ?string
    {
        foreach (self::ESCAPES as $function) {
            if (preg_match('/(?<![a-z0-9_])"?'.preg_quote($function, '/').'"?\s*\(/i', $sql) === 1) {
                return $function;
            }
        }

        return null;
    }

    /**
     * The statement as PostgreSQL would read it: comments are not instructions, and
     * a DELETE hiding behind "-- SELECT 1" is the oldest trick there is.
     *
     * Scanned rather than regex-replaced, because a comment is only a comment
     * outside a string. A pattern that deletes from a double hyphen to the end of
     * the line deletes the whole of SELECT '--', pg_read_file('/etc/passwd') after
     * the two characters inside the literal -- the entire payload -- while
     * PostgreSQL, which knows those two characters are a string, runs every bit of
     * it. The same holds for a block-comment opener inside a literal, and for
     * dollar-quoted bodies, where a comment marker is just text.
     *
     * So literals are copied through untouched and only comments between them are
     * removed. Both quote styles allow their delimiter to be doubled to escape it,
     * and block comments nest, and both of those are handled where they are read.
     */
    private function readable(string $statement): string
    {
        $sql = '';
        $length = strlen($statement);
        $position = 0;

        while ($position < $length) {
            $character = $statement[$position];

            if ($character === '$'
                && preg_match('/\G(\$[a-zA-Z_]\w*\$|\$\$)/', $statement, $matches, 0, $position) === 1
            ) {
                $end = $this->closingTag($statement, $position, $matches[1]);
                $sql .= substr($statement, $position, $end - $position);
                $position = $end;

                continue;
            }

            if ($character === "'" || $character === '"') {
                $end = $this->closingQuote($statement, $position, $character);
                $sql .= substr($statement, $position, $end - $position);
                $position = $end;

                continue;
            }

            if ($this->at($statement, $position, '--')) {
                $newline = strpos($statement, "\n", $position);
                $position = $newline === false ? $length : $newline;
                $sql .= ' ';

                continue;
            }

            if ($this->at($statement, $position, '/*')) {
                $position = $this->closingBlockComment($statement, $position);
                $sql .= ' ';

                continue;
            }

            $sql .= $character;
            $position++;
        }

        // Everybody types the trailing semicolon; nobody means a second statement by it.
        return trim(rtrim(trim($sql), ';'));
    }

    /**
     * Where a quoted literal or identifier ends. A doubled delimiter is an escaped
     * one and not the end; an unterminated literal runs to the end of the string,
     * which PostgreSQL will reject on its own terms in a moment.
     */
    private function closingQuote(string $statement, int $start, string $quote): int
    {
        $length = strlen($statement);
        $position = $start + 1;

        while ($position < $length) {
            if ($statement[$position] !== $quote) {
                $position++;

                continue;
            }

            if ($position + 1 < $length && $statement[$position + 1] === $quote) {
                $position += 2;

                continue;
            }

            return $position + 1;
        }

        return $length;
    }

    /**
     * Where a dollar-quoted body ends: at the next occurrence of its own opening
     * tag, which is the whole point of the syntax.
     */
    private function closingTag(string $statement, int $start, string $tag): int
    {
        $end = strpos($statement, $tag, $start + strlen($tag));

        return $end === false ? strlen($statement) : $end + strlen($tag);
    }

    /**
     * Where a block comment ends.
     *
     * Counting depth rather than looking for the first closing marker, because
     * PostgreSQL's block comments nest: an inner opener has to be closed before
     * the outer one is, and a scanner that stops at the first close leaves the
     * remainder of an outer comment in the statement it hands on.
     */
    private function closingBlockComment(string $statement, int $start): int
    {
        $length = strlen($statement);
        $depth = 0;
        $position = $start;

        while ($position < $length) {
            if ($this->at($statement, $position, '/*')) {
                $depth++;
                $position += 2;

                continue;
            }

            if ($this->at($statement, $position, '*/')) {
                $depth--;
                $position += 2;

                if ($depth === 0) {
                    return $position;
                }

                continue;
            }

            $position++;
        }

        return $length;
    }

    /**
     * Whether the statement carries this two-character marker at this offset,
     * without copying the rest of the string to find out. Every marker the scanner
     * looks for is two characters, and comparing them directly keeps the scan
     * linear over a statement rather than quadratic.
     */
    private function at(string $statement, int $position, string $marker): bool
    {
        return ($statement[$position] ?? '') === $marker[0]
            && ($statement[$position + 1] ?? '') === $marker[1];
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
