<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Console;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Log;

/**
 * Writes down who ran what, and when.
 *
 * The console is read-only, which bounds what it can break and not at all what it
 * can see: an authorized reader can select every row in the database. In most of
 * the places this package is pointed at, somebody eventually has to answer the
 * question "who looked at that, and when" — and until now nothing recorded it.
 *
 * This is a log line, not a table. Vacuum's only write path is History, which
 * writes to the application's own database and never to the inspected one, and an
 * audit trail is exactly the sort of thing that belongs in the log pipeline the
 * application already ships, retains and alerts on, rather than in a table this
 * package would have to invent, migrate and prune.
 */
final readonly class ConsoleAudit
{
    public function __construct(private Repository $config) {}

    /**
     * A statement that ran, whether or not it succeeded.
     *
     * A statement PostgreSQL refused is still a statement somebody ran, and on the
     * paths this exists for it is often the more interesting one, so the caller
     * records the attempt rather than only the success.
     */
    public function record(string $statement, ?int $rows, float $milliseconds, string $connection): void
    {
        if (! $this->enabled()) {
            return;
        }

        $context = [
            'user' => $this->user(),
            'statement' => $statement,
            'rows' => $rows,
            'milliseconds' => round($milliseconds, 1),
            'connection' => $connection,
        ];

        $channel = $this->config->get('vacuum.console.audit_channel');

        // Named channel when the application names one; otherwise the application's
        // own default. Defaulting rather than inventing a channel is deliberate: an
        // audit line that goes nowhere because a channel was misspelled is worse
        // than one sitting in the ordinary log.
        if (is_string($channel) && $channel !== '') {
            Log::channel($channel)->info('Vacuum console statement', $context);

            return;
        }

        Log::info('Vacuum console statement', $context);
    }

    private function enabled(): bool
    {
        return (bool) $this->config->get('vacuum.console.audit', true);
    }

    /**
     * Whoever the application says is asking. Vacuum authorizes through a callback
     * rather than a policy, so there is no user model it may assume — only an
     * identifier, when the request carries one at all.
     *
     * An application is free to key its users on anything, and getAuthIdentifier
     * promises nothing about what comes back. Anything that is not a plain scalar is
     * recorded as null rather than coerced: "no identifier" is a true statement,
     * where a stringified object would be a made-up one in an audit trail.
     */
    private function user(): int|string|null
    {
        $identifier = request()->user()?->getAuthIdentifier();

        return is_int($identifier) || is_string($identifier) ? $identifier : null;
    }
}
