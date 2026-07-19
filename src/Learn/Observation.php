<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Learn;

/**
 * What a lesson found when it looked at the reader's own database. Rows
 * arrive already formatted for display -- a value object knows what it is,
 * and only the lesson knows how it should read -- so the view has nothing
 * left to decide beyond where to put them on the page.
 */
final readonly class Observation
{
    /**
     * @param  string  $headline  One sentence naming the reader's own data. This is
     *                            the sentence no explainer on the internet can write.
     * @param  list<string>  $columns
     * @param  list<list<string>>  $rows  Already formatted for display: a value object
     *                                    knows what it is, and only the lesson knows how
     *                                    it should read.
     * @param  string|null  $note  Why the rows are empty, when they are. An empty
     *                             observation is never rendered as an empty table.
     */
    public function __construct(
        public string $headline,
        public array $columns = [],
        public array $rows = [],
        public ?string $note = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }
}
