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
    /**
     * @param  string|null  $remediation  A statement that would put it right, if one would.
     * @param  string|null  $evidence  The thing being complained about, shown verbatim.
     * @param  string|null  $query  A read-only statement that shows the reader what the rule
     *                              saw, ready to open in the console. Never the remediation:
     *                              that one writes, and the console refuses to write.
     */
    public function __construct(
        public string $rule,
        public string $subject,
        public Severity $severity,
        public string $summary,
        public string $impact,
        public ?string $remediation = null,
        public ?string $evidence = null,
        public ?string $query = null,
    ) {}
}
