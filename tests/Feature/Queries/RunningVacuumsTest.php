<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Queries\RunningVacuums;
use Heyosseus\Vacuum\Support\SqlRepository;
use Illuminate\Support\Facades\DB;

it('asks the server what it is vacuuming right now', function (): void {
    // Nothing is vacuuming a database this quiet, and the honest answer to that
    // question is an empty list. What this proves is that the statement runs: that
    // pg_stat_progress_vacuum exists, the joins hold, and the ignored schemas bind.
    DB::statement('VACUUM');

    expect(app(RunningVacuums::class)->all())->toBe([]);
});

it('reads a vacuum in progress the way postgresql reports one', function (): void {
    // A vacuum over a test table is over in milliseconds, so catching one in flight
    // would mean a test that depends on timing. The row is PostgreSQL's, built with
    // the columns and types the real view has; only its origin is arranged.
    app()->instance(SqlRepository::class, new SqlRepository(__DIR__.'/../../fixtures/sql'));

    $vacuums = app(RunningVacuums::class)->all();

    expect($vacuums)->toHaveCount(1);

    $vacuum = $vacuums[0];

    expect($vacuum->pid)->toBe(4242)
        ->and($vacuum->qualifiedName())->toBe('public.pallets')
        ->and($vacuum->phase)->toBe('vacuuming indexes')
        ->and($vacuum->percentScanned())->toBe(25.0)
        ->and($vacuum->indexPasses)->toBe(2)
        ->and($vacuum->automatic)->toBeTrue()
        ->and($vacuum->startedAt)->not->toBeNull();
});
