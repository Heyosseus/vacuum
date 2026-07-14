<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor\Inspections;

use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Inspection;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Advisor\StatementRule;
use Heyosseus\Vacuum\Queries\Statements;
use Heyosseus\Vacuum\Values\Capabilities;

/**
 * Puts every statement rule to the queries pg_stat_statements has been watching.
 */
final readonly class StatementInspection implements Inspection
{
    private const string EXTENSION = 'pg_stat_statements';

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
        if (! $this->capabilities->has(self::EXTENSION)) {
            return [$this->notInstalled()];
        }

        $findings = [];

        foreach ($this->statements->slowest() as $statement) {
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
     * Without the extension there is no data at all, and a panel with no data in it
     * reads as "no slow queries" rather than as "nobody is looking".
     */
    private function notInstalled(): Finding
    {
        return new Finding(
            rule: 'extension-missing',
            subject: 'database',
            severity: Severity::Info,
            summary: 'pg_stat_statements is not installed, so nothing is keeping track of what your queries cost.',
            impact: 'It is the only way PostgreSQL will tell you which queries are slow, and it is not on by '
                .'default. Creating the extension is not enough on its own: the library has to be listed in '
                .'shared_preload_libraries, which is read at startup, so a server that has never loaded it '
                .'needs a restart. Managed providers usually have it loaded already, and you need only the '
                .'CREATE EXTENSION.',
            remediation: 'CREATE EXTENSION pg_stat_statements;',
        );
    }
}
