<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Console\Support;

use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Severity;
use JsonException;

/**
 * The findings a project has decided not to act on yet.
 *
 * Without this the linter is unusable on any application older than about a
 * month: the first run against a real schema prints four hundred findings, the
 * reader has no way to silence any of them, and the package is removed the same
 * afternoon. A committed baseline turns that into "nothing new is wrong", which
 * is the only question a pipeline can usefully ask of a legacy codebase.
 *
 * A finding is matched on its rule and its subject and on nothing else. Not the
 * summary, not the severity -- so rewording a rule's prose, or making it more
 * serious in a later release, never invalidates a file somebody committed months
 * ago. That works only because schema-rule subjects are stable and
 * data-independent: public.orders.customer_id means the same thing on every run,
 * on every machine, forever. It is not true of slow-statement or dead-tuples,
 * whose subjects move with the data, and that is why vacuum:check has no baseline
 * and should not have one.
 *
 * This class is pure. Paths and files belong to the command.
 */
final readonly class Baseline
{
    /**
     * @param  array<string, list<string>>  $entries  Rule to the subjects excused under it.
     */
    private function __construct(private array $entries) {}

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @param  list<Finding>  $findings
     */
    public static function record(array $findings): self
    {
        $entries = [];

        foreach ($findings as $finding) {
            $entries[$finding->rule][] = $finding->subject;
        }

        foreach ($entries as $rule => $subjects) {
            $unique = array_values(array_unique($subjects));
            sort($unique);
            $entries[$rule] = $unique;
        }

        ksort($entries);

        return new self($entries);
    }

    /**
     * A baseline that could not be read is no baseline at all.
     *
     * Reporting everything is the safe direction to fail in: a corrupt file
     * should not take a pipeline down, and it must not silently excuse findings
     * it never actually listed.
     */
    public static function decode(string $json): self
    {
        try {
            /** @var mixed $document */
            $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::none();
        }

        if (! is_array($document)) {
            return self::none();
        }

        $findings = $document['findings'] ?? null;

        if (! is_array($findings)) {
            return self::none();
        }

        $entries = [];

        foreach ($findings as $rule => $subjects) {
            if (! is_string($rule)) {
                continue;
            }
            if (! is_array($subjects)) {
                continue;
            }

            foreach ($subjects as $subject) {
                if (is_string($subject)) {
                    $entries[$rule][] = $subject;
                }
            }
        }

        return new self($entries);
    }

    public function suppresses(Finding $finding): bool
    {
        return in_array($finding->subject, $this->entries[$finding->rule] ?? [], true);
    }

    /**
     * Entries that match nothing any more, as findings of their own.
     *
     * Reported rather than pruned silently, and Info rather than a fault: the
     * defect being gone is good news, but a baseline nobody tidies becomes a
     * place the next one hides.
     *
     * @param  list<Finding>  $findings
     * @return list<Finding>
     */
    public function stale(array $findings): array
    {
        $present = [];

        foreach ($findings as $finding) {
            $present[$finding->rule.'|'.$finding->subject] = true;
        }

        $stale = [];

        foreach ($this->entries as $rule => $subjects) {
            foreach ($subjects as $subject) {
                if (isset($present[$rule.'|'.$subject])) {
                    continue;
                }

                $stale[] = new Finding(
                    rule: 'baseline-stale',
                    subject: $subject,
                    severity: Severity::Info,
                    summary: "The baseline still excuses {$rule} here, and the rule no longer fires.",
                    impact: 'Nothing is wrong with this subject any more. Regenerate the baseline so the '
                        .'file describes what is actually outstanding, rather than accumulating entries '
                        .'that excuse nothing and hide the next thing that does.',
                );
            }
        }

        return $stale;
    }

    public function encode(string $version): string
    {
        return json_encode([
            'generated_at' => date(DATE_ATOM),
            'vacuum' => $version,
            'findings' => $this->entries,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }
}
