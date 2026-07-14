<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Queries\Sessions;
use Heyosseus\Vacuum\Values\Session;
use Illuminate\Support\Facades\DB;

afterEach(function (): void {
    DB::connection('pgsql_bystander')->rollBack();
    DB::purge('pgsql_bystander');
});

// Not session(): that is one of Laravel's own global helpers, and PHP will not
// hold two functions of the same name however differently they are used.
function backend(int $pid): ?Session
{
    return collect(app(Sessions::class)->all())
        ->firstWhere(fn (Session $session): bool => $session->pid === $pid);
}

function pidOf(string $connection): int
{
    /** @var object{pid: int} $row */
    $row = DB::connection($connection)->selectOne('SELECT pg_backend_pid() AS pid');

    return (int) $row->pid;
}

it('sees the session it is asking down', function (): void {
    $session = backend(pidOf('pgsql'));

    expect($session)->not->toBeNull()
        ->and($session?->active())->toBeTrue()
        ->and($session?->blocked())->toBeFalse();
});

it('sees a session sitting idle inside a transaction', function (): void {
    $bystander = pidOf('pgsql_bystander');

    DB::connection('pgsql_bystander')->beginTransaction();
    DB::connection('pgsql_bystander')->select('SELECT 1');

    $session = backend($bystander);

    expect($session?->idleInTransaction())->toBeTrue()
        ->and($session?->transactionSeconds)->toBeGreaterThanOrEqual(0);
});
