<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor;

/**
 * A single number for the database, worked out from the findings and nothing else.
 *
 * Deliberately not a second opinion. If the score came from its own arithmetic
 * over its own metrics, it could disagree with the list of findings printed
 * beneath it, and a green 92% sitting above three critical problems is the way
 * these dashboards usually lie. Here the score cannot say anything the findings do
 * not: it is a hundred, minus what the findings cost.
 */
final readonly class Health
{
    /** One rule can only cost so much, however many tables it fires on. */
    private const int MOST_ONE_RULE_CAN_COST = 25;

    /**
     * @param  array<string, int>  $deductions  What each rule cost, dearest first.
     */
    private function __construct(
        public int $score,
        public Grade $grade,
        public array $deductions,
    ) {}

    /**
     * @param  list<Finding>  $findings
     */
    public static function from(array $findings): self
    {
        $costs = [];

        foreach ($findings as $finding) {
            $costs[$finding->rule] = ($costs[$finding->rule] ?? 0) + $finding->severity->weight();
        }

        $deductions = [];

        foreach ($costs as $rule => $cost) {
            // Forty bloated tables are one problem, not forty. Uncapped, a noisy
            // rule would bury a blocked session that is taking the site down now.
            $capped = min($cost, self::MOST_ONE_RULE_CAN_COST);

            if ($capped > 0) {
                $deductions[$rule] = $capped;
            }
        }

        arsort($deductions);

        $score = max(0, 100 - array_sum($deductions));

        return new self($score, Grade::for($score), $deductions);
    }
}
