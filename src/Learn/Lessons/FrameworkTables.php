<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Learn\Lessons;

use Heyosseus\Vacuum\Learn\Branch;
use Heyosseus\Vacuum\Learn\Lesson;
use Heyosseus\Vacuum\Learn\Observation;
use Heyosseus\Vacuum\Learn\Tier;
use Heyosseus\Vacuum\Learn\Tree;
use Heyosseus\Vacuum\Queries\TableProfiles;
use Heyosseus\Vacuum\Values\TableProfile;

/**
 * Shows a reader that the tables Laravel writes for them -- sessions, the queue,
 * the cache -- are ordinary PostgreSQL tables with all the costs of ordinary
 * PostgreSQL tables, and that nobody ever looks at them because nobody ever
 * wrote the migration by hand.
 */
final readonly class FrameworkTables implements Lesson
{
    /**
     * The tables Laravel's `session:table`, `queue:table`, `queue:failed-table`,
     * `queue:batches-table` and `cache:table` Artisan commands create, by the
     * name those commands give them.
     *
     * This is recognition by convention, not by type: Vacuum runs against a raw
     * PostgreSQL connection and never autoloads the host application's classes,
     * so it has no `SessionServiceProvider` to ask "which table is yours." A
     * table is recognised only because it happens to be named `sessions`, and a
     * reader who renamed the table when they generated the migration -- or who
     * points the session driver at a table called `web_sessions` -- will not
     * see it show up here. That is a real limitation, not an oversight, and the
     * lesson says so rather than pretending the list is complete.
     *
     * @var list<string>
     */
    private const array FRAMEWORK = ['sessions', 'jobs', 'failed_jobs', 'job_batches', 'cache', 'cache_locks'];

    /**
     * The dead share above which a framework table is worth putting on the
     * tree. Set higher than {@see DeadTuples::POOR}'s 0.2 on purpose: a queue
     * or session table is expected to carry more garbage between vacuums than
     * an ordinary table, because every row in it is short-lived by design, so
     * the bar for "this one specifically needs attention" has to sit above the
     * noise these tables generate just by doing their job. This is a judgement
     * call, not a number PostgreSQL hands you -- there is no threshold in the
     * documentation that says a session table is fine at 25% and not at 35%.
     */
    private const float POOR = 0.3;

    public function __construct(private TableProfiles $profiles) {}

    public function slug(): string
    {
        return 'framework-tables';
    }

    public function title(): string
    {
        return 'The tables Laravel writes for you';
    }

    public function tier(): Tier
    {
        return Tier::Eloquent;
    }

    public function hook(): string
    {
        return 'Sessions, the queue and the cache are tables too. See what churning them costs.';
    }

    public function after(): ?string
    {
        return null;
    }

    public function tree(): Tree
    {
        return $this->fork($this->profiles->all());
    }

    /**
     * The judgement, separated from the fetch so it can be exercised against
     * profiles that were built rather than measured.
     *
     * Public deliberately, for the same reason as {@see Fillfactor::fork()}: a
     * live database is never guaranteed to be running the database session or
     * queue drivers at all, let alone to have one framework table sitting at
     * the default fillfactor and a different one that autovacuum has never
     * touched, and the fork needs to be provably correct on both before it is
     * trusted against real data.
     *
     * @param  list<TableProfile>  $profiles
     */
    public function fork(array $profiles): Tree
    {
        $found = $this->framework($profiles);

        $poor = array_values(array_filter(
            $found,
            static fn (TableProfile $p): bool => $p->deadTupleRatio() > self::POOR,
        ));
        $healthy = array_values(array_filter(
            $found,
            static fn (TableProfile $p): bool => $p->deadTupleRatio() <= self::POOR,
        ));

        $stillDefault = array_values(array_filter($poor, static fn (TableProfile $p): bool => $p->fillfactor === null));
        $neverAutovacuumed = array_values(array_filter($poor, static fn (TableProfile $p): bool => ! $p->lastAutovacuum instanceof \Carbon\CarbonImmutable));

        return new Tree('These tables churn harder than anything you wrote. What should you do about it?', [
            new Branch(
                condition: 'A framework table is carrying a high dead share and is still at the default fillfactor.',
                outcome: 'It is updated constantly and has no room on the page for the new row version, so '
                    .'every update leaves the page and pays every index. Lowering its fillfactor is the '
                    .'cheap fix.',
                landed: array_map(static fn (TableProfile $p): string => $p->qualifiedName(), $stillDefault),
                fix: $stillDefault === []
                    ? null
                    : 'alter table '.$stillDefault[0]->qualifiedName().' set (fillfactor = 85);',
            ),
            new Branch(
                condition: 'A framework table is carrying a high dead share and autovacuum has never run on it.',
                outcome: "Autovacuum's threshold to start is a share of the table rather than a fixed count, "
                    .'so a big table is allowed a lot of garbage before anything moves. Lowering '
                    .'autovacuum_vacuum_scale_factor for that table makes it act sooner.',
                landed: array_map(static fn (TableProfile $p): string => $p->qualifiedName(), $neverAutovacuumed),
                fix: $neverAutovacuumed === []
                    ? null
                    : 'alter table '.$neverAutovacuumed[0]->qualifiedName().' set (autovacuum_vacuum_scale_factor = 0.05);',
            ),
            new Branch(
                condition: 'Nothing here is in trouble.',
                outcome: 'These tables are healthy right now. Worth re-checking once traffic grows -- they '
                    .'are exactly the tables that will feel it first.',
                landed: array_map(static fn (TableProfile $p): string => $p->qualifiedName(), $healthy),
            ),
        ]);
    }

    public function observe(): Observation
    {
        $found = $this->framework($this->profiles->all());

        if ($found === []) {
            return new Observation(
                headline: 'None of the six tables Laravel can write for you -- sessions, jobs, failed_jobs, '
                    .'job_batches, cache, cache_locks -- exist in this database.',
                note: 'This application is using the file or Redis driver for sessions, cache and queues '
                    .'instead of the database ones, which is exactly why there is nothing here to show.',
            );
        }

        $ranked = $found;
        usort($ranked, static fn (TableProfile $a, TableProfile $b): int => $b->deadTuples <=> $a->deadTuples);
        $worst = $ranked[0];

        return new Observation(
            headline: 'This database writes '.count($found).' of the six tables Laravel manages for you, and '
                ."`{$worst->qualifiedName()}` carries the most dead rows: ".number_format($worst->deadTuples).'.',
            columns: ['table', 'live rows', 'dead rows', 'dead share', 'HOT share', 'fillfactor'],
            rows: array_map($this->toRow(...), $ranked),
        );
    }

    public function tryIt(): string
    {
        $names = implode(', ', array_map(static fn (string $name): string => "'{$name}'", self::FRAMEWORK));

        return "select relname, n_live_tup, n_dead_tup, last_autovacuum\n"
            ."from pg_stat_user_tables\n"
            ."where relname in ({$names})\n"
            .'order by n_dead_tup desc;';
    }

    /**
     * The subset of profiles PostgreSQL happens to be tracking that carry one
     * of the names a framework Artisan command would have given them.
     *
     * @param  list<TableProfile>  $profiles
     * @return list<TableProfile>
     */
    private function framework(array $profiles): array
    {
        return array_values(array_filter(
            $profiles,
            static fn (TableProfile $p): bool => in_array($p->name, self::FRAMEWORK, true),
        ));
    }

    /**
     * @return list<string>
     */
    private function toRow(TableProfile $profile): array
    {
        $hot = $profile->hotUpdateRatio();

        return [
            $profile->qualifiedName(),
            number_format($profile->liveTuples),
            number_format($profile->deadTuples),
            number_format($profile->deadTupleRatio() * 100, 1).'%',
            $hot === null ? '—' : number_format($hot * 100, 1).'%',
            $profile->fillfactor === null ? '100 (default)' : (string) $profile->fillfactor,
        ];
    }
}
