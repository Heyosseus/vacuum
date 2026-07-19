<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Exceptions\InvalidCurriculum;
use Heyosseus\Vacuum\Learn\Curriculum;
use Heyosseus\Vacuum\Learn\Lesson;
use Heyosseus\Vacuum\Learn\Node;
use Heyosseus\Vacuum\Learn\Observation;
use Heyosseus\Vacuum\Learn\Tier;
use Heyosseus\Vacuum\Learn\Tree;

/**
 * A lesson built for a test and nothing else: the curriculum only ever reads
 * the four methods below, so a fake that answers them is a real lesson as far
 * as the graph is concerned.
 */
function lesson(string $slug, Tier $tier, ?string $after = null): Lesson
{
    return new readonly class($slug, $tier, $after) implements Lesson
    {
        public function __construct(
            private string $slug,
            private Tier $tier,
            private ?string $after,
        ) {}

        public function slug(): string
        {
            return $this->slug;
        }

        public function title(): string
        {
            return $this->slug;
        }

        public function tier(): Tier
        {
            return $this->tier;
        }

        public function hook(): string
        {
            return 'hook';
        }

        public function observe(): Observation
        {
            return new Observation(headline: 'headline');
        }

        public function tryIt(): ?string
        {
            return null;
        }

        public function after(): ?string
        {
            return $this->after;
        }

        public function tree(): ?Tree
        {
            return null;
        }
    };
}

it('finds a lesson by its slug', function (): void {
    $curriculum = new Curriculum([lesson('dead-tuples', Tier::Maintenance)]);

    expect($curriculum->find('dead-tuples'))->not->toBeNull()
        ->and($curriculum->find('dead-tuples')?->slug())->toBe('dead-tuples');
});

it('returns null for a slug no lesson claims', function (): void {
    $curriculum = new Curriculum([lesson('dead-tuples', Tier::Maintenance)]);

    expect($curriculum->find('nonsense'))->toBeNull();
});

it('degrades to an empty curriculum when nothing is registered', function (): void {
    $curriculum = new Curriculum([]);

    expect($curriculum->all())->toBe([])
        ->and($curriculum->byTier())->toBe([]);
});

it('groups lessons by tier in Tier::order(), omitting empty tiers', function (): void {
    $curriculum = new Curriculum([
        lesson('unused-indexes', Tier::Indexes),
        lesson('dead-tuples', Tier::Maintenance),
        lesson('row-versions', Tier::Storage),
        lesson('fillfactor', Tier::Storage),
    ]);

    $byTier = $curriculum->byTier();

    expect(array_keys($byTier))->toBe(['Storage & MVCC', 'Indexes', 'Maintenance'])
        ->and(array_map(static fn (Lesson $l): string => $l->slug(), $byTier['Storage & MVCC']))
        ->toBe(['row-versions', 'fillfactor']);
});

it('nests a lesson under the prerequisite it names', function (): void {
    $curriculum = new Curriculum([
        lesson('row-versions', Tier::Storage),
        lesson('fillfactor', Tier::Storage, after: 'row-versions'),
    ]);

    $tree = $curriculum->tree();

    expect($tree['Storage & MVCC'])->toHaveCount(1)
        ->and($tree['Storage & MVCC'][0]->lesson->slug())->toBe('row-versions')
        ->and($tree['Storage & MVCC'][0]->children)->toHaveCount(1)
        ->and($tree['Storage & MVCC'][0]->children[0]->lesson->slug())->toBe('fillfactor');
});

it('nests to any depth the edges describe', function (): void {
    $curriculum = new Curriculum([
        lesson('dead-tuples', Tier::Maintenance),
        lesson('autovacuum', Tier::Maintenance, after: 'dead-tuples'),
        lesson('bloat', Tier::Maintenance, after: 'autovacuum'),
    ]);

    $root = $curriculum->tree()['Maintenance'][0];

    expect($root->children[0]->children[0]->lesson->slug())->toBe('bloat');
});

it('leaves a cross-tier prerequisite flat, because no panel renders its parent', function (): void {
    $curriculum = new Curriculum([
        lesson('fillfactor', Tier::Storage),
        lesson('timestamps-and-hot', Tier::Eloquent, after: 'fillfactor'),
    ]);

    $tree = $curriculum->tree();

    expect($tree['Eloquent & Laravel'])->toHaveCount(1)
        ->and($tree['Eloquent & Laravel'][0]->lesson->slug())->toBe('timestamps-and-hot')
        ->and($tree['Eloquent & Laravel'][0]->children)->toBe([]);
});

it('refuses a prerequisite no lesson claims', function (): void {
    expect(fn (): Curriculum => new Curriculum([lesson('fillfactor', Tier::Storage, after: 'nonsense')]))
        ->toThrow(InvalidCurriculum::class);
});

it('refuses a cycle', function (): void {
    expect(fn (): Curriculum => new Curriculum([
        lesson('a', Tier::Storage, after: 'b'),
        lesson('b', Tier::Storage, after: 'a'),
    ]))->toThrow(InvalidCurriculum::class);
});

it('builds a tree for every registered lesson', function (): void {
    $tree = app(Curriculum::class)->tree();

    $counted = 0;

    $count = function (array $nodes) use (&$count, &$counted): void {
        foreach ($nodes as $node) {
            expect($node)->toBeInstanceOf(Node::class);
            $counted++;
            $count($node->children);
        }
    };

    foreach ($tree as $nodes) {
        $count($nodes);
    }

    expect($counted)->toBe(count(app(Curriculum::class)->all()));
});
