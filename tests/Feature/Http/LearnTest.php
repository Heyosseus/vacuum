<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Learn\Curriculum;
use Heyosseus\Vacuum\Vacuum;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Authorization is inherited from the route group; every test here is about what
// the section renders once a request has been let through, not about the gate.
beforeEach(function (): void {
    Vacuum::auth(static fn (Request $request): bool => true);
});

/**
 * The Learn section end to end: the index groups the registered lessons into
 * tiers, and every lesson renders its own three bands against the live test
 * database. Nothing here asserts on a statistic's value -- these run against a
 * real PostgreSQL whose numbers move between one test and the next -- only that
 * the page was built out of the reader's own data without falling over.
 */
it('lists the lessons by tier', function (): void {
    $this->get('/vacuum/learn')
        ->assertOk()
        ->assertSee('Storage &amp; MVCC', false)
        ->assertSee('Fillfactor and HOT updates');
});

it('renders every registered lesson', function (): void {
    $lessons = app(Curriculum::class)->all();

    expect($lessons)->not->toBeEmpty();

    foreach ($lessons as $lesson) {
        $this->get('/vacuum/learn/'.$lesson->slug())
            ->assertOk()
            ->assertSee('In your database', false)
            ->assertSee($lesson->title(), false);
    }
});

/**
 * The three bands are one panel each, and the third only exists when the lesson
 * has something to hand over. Fillfactor always does, and it always has rows to
 * show, because the suite has been writing to this database for the whole run.
 */
it('renders a lesson with the reader own data', function (): void {
    $this->get('/vacuum/learn/fillfactor')
        ->assertOk()
        ->assertSee('In your database', false)
        ->assertSee('Try it', false)
        ->assertSee('pg_stat_user_tables', false);
});

/**
 * The headline is the one place a table's own name reaches the page, and the
 * lessons mark those names with backticks. They must arrive as markup, and the
 * escaping must have happened before they did.
 */
it('marks the table names in a headline as code', function (): void {
    // The row versions headline names a table, and it can only do that if the
    // database has one. Nothing else in this file depends on a particular table
    // existing, so this test makes its own rather than trust the run order.
    DB::statement('create table if not exists learn_headline_probe (id int primary key)');

    try {
        $this->get('/vacuum/learn/row-versions')
            ->assertOk()
            ->assertSee('<code>public.', false);
    } finally {
        DB::statement('drop table if exists learn_headline_probe');
    }
});

it('offers the next lesson in the curriculum', function (): void {
    $lessons = app(Curriculum::class)->all();

    $this->get('/vacuum/learn/'.$lessons[0]->slug())
        ->assertOk()
        ->assertSee('all lessons', false)
        ->assertSee($lessons[1]->title(), false);
});

it('404s on a slug no lesson claims', function (): void {
    $this->get('/vacuum/learn/nonsense')->assertNotFound();
});

it('renders the fourth band when a lesson resolves a fork', function (): void {
    $this->get('/vacuum/learn/fillfactor')
        ->assertOk()
        ->assertSee('What to do about it', false);
});

it('says what a lesson builds on', function (): void {
    $this->get('/vacuum/learn/fillfactor')
        ->assertOk()
        ->assertSee('builds on', false)
        ->assertSee('Row versions', false);
});

it('omits the fourth band entirely when a lesson has no fork', function (): void {
    $this->get('/vacuum/learn/row-versions')
        ->assertOk()
        ->assertDontSee('What to do about it', false);
});

it('indents a lesson under the one it builds on', function (): void {
    $this->get('/vacuum/learn')
        ->assertOk()
        ->assertSee('tree__child', false);
});
