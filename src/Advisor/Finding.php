<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor;

/**
 * Something the advisor believes is wrong, why it costs you, and the statement
 * that would put it right.
 *
 * The remediation is a string. Vacuum shows it and never executes it: the whole
 * package has no code path that writes to the inspected database, which is what
 * makes its safety claim auditable rather than merely asserted.
 */
final readonly class Finding
{
    public function __construct(
        public string $rule,
        public string $subject,
        public Severity $severity,
        public string $summary,
        public string $impact,
        public ?string $remediation = null,
    ) {}

    /**
     * @return array{rule: string, subject: string, severity: string, summary: string, impact: string, remediation: string|null}
     */
    public function toArray(): array
    {
        return [
            'rule' => $this->rule,
            'subject' => $this->subject,
            'severity' => $this->severity->value,
            'summary' => $this->summary,
            'impact' => $this->impact,
            'remediation' => $this->remediation,
        ];
    }
}
