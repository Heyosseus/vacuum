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
 */
final readonly class StatementGuard
{
    /**
     * @var list<string>
     */
    private const array READS = ['SELECT', 'WITH', 'EXPLAIN', 'SHOW', 'TABLE', 'VALUES'];

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
