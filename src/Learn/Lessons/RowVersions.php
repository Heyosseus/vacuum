<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Learn\Lessons;

use Heyosseus\Vacuum\Internals\Explorers\RowVersions as RowVersionExplorer;
use Heyosseus\Vacuum\Internals\Values\RowVersion;
use Heyosseus\Vacuum\Learn\Lesson;
use Heyosseus\Vacuum\Learn\Observation;
use Heyosseus\Vacuum\Learn\Tier;
use Heyosseus\Vacuum\Learn\Tree;
use Heyosseus\Vacuum\Queries\TableProfiles;
use Heyosseus\Vacuum\Support\Identifier;
use Heyosseus\Vacuum\Values\TableProfile;
use InvalidArgumentException;

/**
 * Shows a reader the physical row versions PostgreSQL is holding for their
 * own largest table, using nothing but the ctid, xmin and xmax every table
 * already carries.
 *
 * The row version explorer needs no extension and no privilege beyond an
 * ordinary SELECT, which is exactly why it is safe to run here regardless of
 * whether {@see RowVersionExplorer::availability()}
 * has the internals page switched on -- that flag gates a different feature,
 * not this query.
 */
final readonly class RowVersions implements Lesson
{
    /** Enough physical row versions to make the shape visible without scrolling. */
    private const int ROWS = 10;

    public function __construct(
        private TableProfiles $profiles,
        private RowVersionExplorer $explorer,
    ) {}

    public function slug(): string
    {
        return 'row-versions';
    }

    public function title(): string
    {
        return 'Row versions and MVCC';
    }

    public function tier(): Tier
    {
        return Tier::Storage;
    }

    public function hook(): string
    {
        return "See the copies PostgreSQL is keeping of your own table's rows.";
    }

    public function after(): ?string
    {
        return null;
    }

    public function tree(): ?Tree
    {
        return null;
    }

    public function observe(): Observation
    {
        $largest = $this->largest();

        if (! $largest instanceof TableProfile) {
            return new Observation(
                headline: 'This database has no tables yet.',
                note: 'Row versions live on a table, and there is no table here to look at. '.
                    'Create one and this lesson will show its rows the moment it has any.',
            );
        }

        $name = $largest->qualifiedName();

        try {
            $versions = $this->explorer->explore($largest->schema, $largest->name, self::ROWS);
            // TableProfiles::all() and the explorer's own catalog check are two
            // separate reads; a table dropped between them is the only way the catch
            // below fires, and the result is no different from the table having
            // always been empty. Unreachable on purpose: there is no way to lose a
            // table between two statements from inside a test, and a lesson page that
            // 500s because somebody ran a migration while it loaded is worse than a
            // band with nothing in it.
            // @codeCoverageIgnoreStart
        } catch (InvalidArgumentException) {
            $versions = [];
            // @codeCoverageIgnoreEnd
        }

        if ($versions === []) {
            return new Observation(
                headline: "`{$name}` is this database's largest table, and it is empty.",
                note: 'There are no rows in it yet, so there is nothing physical to show. '.
                    'Insert a row and reload this page.',
            );
        }

        return new Observation(
            headline: "`{$name}` is this database's largest table, and here are the physical "
                .'row versions currently on disk for its first '.count($versions).' rows.',
            columns: ['ctid', 'xmin', 'xmax'],
            rows: array_map(
                static fn (RowVersion $version): array => [$version->ctid, $version->xmin, $version->xmax],
                $versions,
            ),
        );
    }

    public function tryIt(): ?string
    {
        $largest = $this->largest();

        if (! $largest instanceof TableProfile) {
            return null;
        }

        return 'select ctid, xmin, xmax, * from '
            .Identifier::qualified($largest->schema, $largest->name)
            .' limit 10;';
    }

    /**
     * The table this lesson talks about. Read fresh rather than cached: observe()
     * and tryIt() are two separate calls, and neither is guaranteed to run first.
     */
    private function largest(): ?TableProfile
    {
        $profiles = $this->profiles->all();

        return $profiles[0] ?? null;
    }
}
