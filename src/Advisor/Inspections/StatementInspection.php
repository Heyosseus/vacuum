<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor\Inspections;

use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Inspection;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Advisor\StatementRule;
use Heyosseus\Vacuum\Queries\Statements;
use Heyosseus\Vacuum\Values\Capabilities;
use Illuminate\Database\QueryException;

/**
 * Puts every statement rule to the queries pg_stat_statements has been watching.
 */
final readonly class StatementInspection implements Inspection
{
    /**
     * What PostgreSQL raises when the extension was created but the library was
     * never preloaded: object_not_in_prerequisite_state, "pg_stat_statements
     * must be loaded via shared_preload_libraries".
     */
    private const string NOT_PRELOADED = '55000';

    /**
     * @param  iterable<StatementRule>  $rules
     */
    public function __construct(
        private Capabilities $capabilities,
        private Statements $statements,
        private iterable $rules,
    ) {}

    /**
     * @return list<Finding>
     */
    public function findings(): array
    {
        if (! $this->capabilities->tracksStatements()) {
            return [$this->notTracking()];
        }

        try {
            $statements = $this->statements->slowest();
        } catch (QueryException $exception) {
            if (! $this->notPreloaded($exception)) {
                throw $exception;
            }

            // The capability probe said the view was readable and the server
            // disagreed anyway. Believe the server, and answer with the same
            // guidance rather than the 500 it would otherwise become.
            return [$this->notTracking()];
        }

        $findings = [];

        foreach ($statements as $statement) {
            foreach ($this->rules as $rule) {
                $finding = $rule->inspect($statement);

                if ($finding instanceof Finding) {
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }

    /**
     * Without a working extension there is no data at all, and a panel with no
     * data in it reads as "no slow queries" rather than as "nobody is looking".
     * Created but never preloaded is the same silence: the view exists, and
     * reading it throws instead of answering.
     */
    private function notTracking(): Finding
    {
        return new Finding(
            rule: 'extension-missing',
            subject: 'database',
            severity: Severity::Unknown,
            summary: 'pg_stat_statements is not active, so nothing is keeping track of what your queries cost.',
            impact: 'It is the only way PostgreSQL will tell you which queries are slow, and it is not on by '
                .'default. Creating the extension is not enough on its own: the library has to be listed in '
                .'shared_preload_libraries, which is read at startup, so a server that has never loaded it '
                .'needs a restart before the view will answer. Managed providers usually have it loaded '
                .'already, and you need only the CREATE EXTENSION.',
            remediation: 'CREATE EXTENSION IF NOT EXISTS pg_stat_statements;',
        );
    }

    private function notPreloaded(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === self::NOT_PRELOADED;
    }
}
