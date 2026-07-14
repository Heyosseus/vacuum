<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor;

enum Severity: string
{
    /** Worth knowing, costing nothing today. */
    case Info = 'info';

    /** Costing something measurable; fix it when convenient. */
    case Warning = 'warning';

    /** Costing something now, or heading somewhere that will hurt. */
    case Critical = 'critical';
}
