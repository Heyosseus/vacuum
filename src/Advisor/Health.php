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
     * The best a database with a critical finding is allowed to be called.
     *
     * Left to the arithmetic, one critical finding costs fifteen and grades a B,
     * and a B is a grade you scroll past. Whether a critical finding is worth
     * fifteen points is a question about the score; whether a database that has
     * one may be called healthy is not.
     */
    private const Grade CEILING_WITH_A_CRITICAL_FINDING = Grade::D;

    /**
     * @param  array<string, int>  $deductions  What each rule cost, dearest first.
     * @param  bool  $capped  Whether the letter is lower than the score alone would
     *                        have given, so the page can say so rather than leave a
     *                        reader to wonder why 85 is a D.
     * @param  int  $unknowns  How many checks could not run at all. These cost the
     *                         score nothing -- an unknown is not a fault -- but a
     *                         grade worked out from half the evidence should say
     *                         which half, so the page can print "of what I could
     *                         see" rather than let a reader assume it saw all of it.
     */
    private function __construct(
        public int $score,
        public Grade $grade,
        public array $deductions,
        public bool $capped,
        public int $unknowns,
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

        $unknowns = 0;

        foreach ($findings as $finding) {
            if ($finding->severity === Severity::Unknown) {
                $unknowns++;
            }
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
        $grade = self::grade($score, $findings);

        return new self(
            $score,
            $grade,
            $deductions,
            capped: $grade !== Grade::for($score),
            unknowns: $unknowns,
        );
    }

    /**
     * @param  list<Finding>  $findings
     */
    private static function grade(int $score, array $findings): Grade
    {
        $grade = Grade::for($score);

        foreach ($findings as $finding) {
            if ($finding->severity === Severity::Critical) {
                return $grade->noBetterThan(self::CEILING_WITH_A_CRITICAL_FINDING);
            }
        }

        return $grade;
    }
}
