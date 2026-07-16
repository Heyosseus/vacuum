<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Console\StatementGuard;
use Heyosseus\Vacuum\Exceptions\RejectedStatement;

/**
 * These all begin with SELECT, so the opener check waves every one of them through,
 * and none of them is stopped by the READ ONLY transaction either: dblink opens a
 * second backend that has its own transaction, and the rest have their effects
 * outside MVCC, which is the only thing READ ONLY constrains.
 *
 * Turning them away is a courtesy, exactly like the DELETE check next to it. The
 * boundary is the role the console connects as — a role that cannot execute these
 * cannot be talked into it by any spelling, and a role that can is not saved by a
 * word list. The list is here so an accident gets a sentence, and so the honest
 * version of the promise is written down somewhere a reader will find it.
 */
$escapes = [
    'a second backend' => "SELECT dblink('dbname=app', 'DELETE FROM users')",
    'a second backend, connected' => "SELECT dblink_connect('dbname=app')",
    'a second backend, executing' => "SELECT dblink_exec('dbname=app', 'DELETE FROM users')",
    'the server filesystem' => "SELECT pg_read_file('/etc/passwd')",
    'the server filesystem, binary' => "SELECT pg_read_binary_file('/etc/passwd')",
    'the server directory listing' => "SELECT pg_ls_dir('/')",
    'killing a backend' => 'SELECT pg_terminate_backend(4242)',
    'cancelling a backend' => 'SELECT pg_cancel_backend(4242)',
    'writing a server file' => "SELECT lo_export(16384, '/tmp/rows')",
    'reading a server file' => "SELECT lo_import('/etc/passwd')",
];

it('turns away a statement that reaches outside the read-only transaction', function (string $statement): void {
    expect(fn (): mixed => app(StatementGuard::class)->check($statement))
        ->toThrow(RejectedStatement::class);
})->with($escapes);

it('says which function it turned away, and that the role is the real answer', function (): void {
    try {
        app(StatementGuard::class)->check("SELECT pg_read_file('/etc/passwd')");
    } catch (RejectedStatement $rejected) {
        expect($rejected->getMessage())->toContain('pg_read_file')
            ->and($rejected->getMessage())->toContain('role');

        return;
    }

    $this->fail('The guard let pg_read_file through.');
});

it('sees through the comment a reader would hide the call behind', function (): void {
    // The guard already strips comments before reading the opener, and the denylist
    // is checked against the same stripped text rather than the raw one.
    expect(fn (): mixed => app(StatementGuard::class)->check(
        "SELECT /* nothing to see */ pg_read_file('/etc/passwd')",
    ))->toThrow(RejectedStatement::class);
});

it('does not turn away a column that merely reads like one of them', function (): void {
    // The denylist matches a function call, not a substring: a table with a column
    // named dblink_status is somebody's ordinary schema, not an escape.
    app(StatementGuard::class)->check('SELECT dblink_status FROM integrations');
    app(StatementGuard::class)->check('SELECT lo_exported_at FROM exports');

    expect(true)->toBeTrue();
});

it('does not turn away the statements vacuum itself offers', function (): void {
    // The rules' own drill-downs read pg_stat_* views, whose names share a prefix
    // with nothing on the list, and must keep working.
    app(StatementGuard::class)->check('SELECT relname, n_dead_tup FROM pg_stat_user_tables');

    expect(true)->toBeTrue();
});
