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

/**
 * Every one of these was admitted before the guard checked the same string it
 * executes and learned what a string literal is.
 *
 * The old readable() stripped comments with two regexes that knew nothing about
 * quoting, so a literal containing -- or the block-comment opener deleted the rest
 * of the statement *from the guard's view only*. The guard then saw `SELECT '`,
 * reported an opener of SELECT, found no denylisted call, and admitted -- while
 * the raw original, payload intact, went to PostgreSQL. Every entry on the
 * denylist fell to that one trick.
 *
 * It remains a courtesy and not a boundary. But a control that fails silently is
 * worse than one that is absent, and that is the part the disclaimer never
 * covered.
 */
$hidden = [
    'behind a line-comment marker in a literal' => "SELECT '--', pg_read_file('/etc/passwd')",
    'behind a block-comment opener in a literal' => "SELECT '/*', pg_read_file('/etc/passwd'), '*/'",
    'behind a dollar-quoted marker' => "SELECT \$\$--\$\$, pg_read_file('/etc/passwd')",
    'behind a literal, opening a second backend' => "SELECT '--', dblink_exec('dbname=app', 'DELETE FROM users')",
    'behind a literal, writing a server file' => "SELECT '--', lo_export(16384, '/tmp/pwned')",
    'behind a doubled quote' => "SELECT 'it''s --', pg_ls_dir('/')",
];

it('refuses a denylisted call hidden inside a string literal', function (string $sql): void {
    app(StatementGuard::class)->check($sql);
})->with($hidden)->throws(RejectedStatement::class);

it('refuses a denylisted call written as a quoted identifier', function (): void {
    // Independent of the literal trick and needing no comment at all: PostgreSQL
    // is perfectly happy with a quoted function name, and the old pattern
    // demanded the parenthesis immediately after the bare one.
    app(StatementGuard::class)->check('SELECT "pg_read_file"(\'/etc/passwd\')');
})->throws(RejectedStatement::class);

it('hands back the statement it approved, so nothing else can be run instead', function (): void {
    // The structural half of the fix. Checking one string and executing another
    // is the hole; returning the checked string is what closes the class of bug
    // rather than the instances of it.
    $checked = app(StatementGuard::class)->check("SELECT 1 -- and a comment\n");

    expect($checked)->toBe('SELECT 1');
});

it('leaves a comment marker inside a literal in the statement it returns', function (): void {
    // The literal is data. Stripping it would change what PostgreSQL is asked,
    // which is the opposite failure and just as wrong.
    expect(app(StatementGuard::class)->check("SELECT '--'"))->toBe("SELECT '--'");
});

it('still strips a comment that really is a comment', function (): void {
    expect(app(StatementGuard::class)->check('SELECT 1 /* nested /* deeper */ still */ + 1'))
        ->toBe('SELECT 1   + 1');
});

it('does not mistake an ordinary column name for a denylisted call', function (): void {
    // A guard that cannot tell dblink_status from dblink( is a guard people
    // switch off.
    app(StatementGuard::class)->check('SELECT dblink_status FROM connections');
})->throwsNoExceptions();

it('does not run off the end of an unterminated literal or comment', function (string $sql): void {
    // Half-typed SQL reaches the guard constantly -- somebody hits enter mid-edit.
    // The scanner has to stop at the end of the string rather than reading past
    // it, and PostgreSQL gets to be the one that complains about the syntax.
    expect(app(StatementGuard::class)->check($sql))->not->toBe('');
})->with([
    'an unterminated dollar quote' => ['SELECT $tag$ still going'],
    'an unterminated block comment' => ['SELECT 1 /* never closed'],
    'an unterminated literal' => ["SELECT 'never closed"],
]);
