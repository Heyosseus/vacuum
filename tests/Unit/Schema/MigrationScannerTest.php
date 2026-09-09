<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Schema\MigrationScanner;

function scan(string $body): array
{
    return (new MigrationScanner)->scan("<?php\n\nreturn new class extends Migration\n{\n".$body."\n};\n");
}

it('finds a created table and its columns', function (): void {
    $entries = scan(<<<'PHP'
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id');
        });
    }
PHP);

    expect($entries)->toHaveKeys(['orders', 'orders.customer_id']);
});

it('records the line each declaration sits on', function (): void {
    $entries = scan(<<<'PHP'
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->foreignId('customer_id');
        });
    }
PHP);

    // The fixture prepends four lines, so the column lands on line 8.
    expect($entries['orders.customer_id'])->toBe(8);
});

it('expands a morphs pair into the two columns it actually creates', function (): void {
    $entries = scan(<<<'PHP'
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->morphs('commentable');
            $table->nullableMorphs('authorable');
        });
    }
PHP);

    expect($entries)->toHaveKeys([
        'comments.commentable_type',
        'comments.commentable_id',
        'comments.authorable_type',
        'comments.authorable_id',
    ]);
});

it('refuses a table name it cannot read', function (): void {
    // A variable table name is not something the tokenizer can resolve, and an
    // anchor pointing at the wrong file is worse than no anchor at all.
    expect(scan(<<<'PHP'
    public function up(): void
    {
        Schema::create($name, function (Blueprint $table) {
            $table->foreignId('customer_id');
        });
    }
PHP))->toBe([]);
});

it('leaves the table scope at the closing brace', function (): void {
    // A column declared after the closure has ended belongs to no table.
    $entries = scan(<<<'PHP'
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->foreignId('customer_id');
        });

        $other->string('stray');
    }
PHP);

    expect($entries)->not->toHaveKey('orders.stray');
});

it('reads Schema::table as well as Schema::create', function (): void {
    $entries = scan(<<<'PHP'
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('note');
        });
    }
PHP);

    expect($entries)->toHaveKey('orders.note');
});

it('keeps the first declaration when a column appears twice', function (): void {
    // The first is where the column was written, which is where the defect was
    // introduced; a later ->index() call is not.
    $entries = scan(<<<'PHP'
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->foreignId('customer_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('customer_id');
        });
    }
PHP);

    expect($entries['orders.customer_id'])->toBe(8);
});

it('ignores a method call whose first argument is not a literal', function (): void {
    $entries = scan(<<<'PHP'
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->foreignId($column);
        });
    }
PHP);

    expect($entries)->toBe(['orders' => 7]);
});

it('does not mistake a chained call for another column', function (): void {
    // The receiver of ->constrained() is foreignId()'s return value, not the
    // Blueprint, so 'users' is a referenced table and not a column of this one.
    $entries = scan(<<<'PHP'
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->foreignId('customer_id')->constrained('users');
        });
    }
PHP);

    expect($entries)->toHaveKey('orders.customer_id')
        ->and($entries)->not->toHaveKey('orders.users');
});

it('reads a column declared with whitespace before the arrow', function (): void {
    // Whitespace is a token, so the receiver check has to walk past it.
    $entries = scan(<<<'PHP'
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table
                ->foreignId('customer_id');
        });
    }
PHP);

    expect($entries)->toHaveKey('orders.customer_id');
});

it('ignores a Schema call that is neither create nor table', function (): void {
    $entries = scan(<<<'PHP'
    public function up(): void
    {
        if (Schema::hasTable('orders')) {
            //
        }
    }
PHP);

    expect($entries)->toBe([]);
});

it('ignores a call whose method name is not itself a literal', function (): void {
    // $table->$method('x') calls through a variable method name, which the
    // tokenizer sees as a T_VARIABLE rather than the T_STRING a real column
    // declaration would be.
    $entries = scan(<<<'PHP'
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->$method('customer_id');
        });
    }
PHP);

    expect($entries)->toBe(['orders' => 7]);
});

it('opens no table scope for DB::table, which runs a query rather than declaring a schema', function (): void {
    // DB::table('orders') and Schema::table('orders') share a method name, but
    // only one of them is a schema declaration. Without checking the receiver, a
    // data-backfill migration's DB::table('orders') would open an 'orders' scope
    // of its own, and every ->method('literal') that followed it in the same
    // method body -- however unrelated -- would register as a column orders
    // never actually gained.
    $entries = scan(<<<'PHP'
    public function up(): void
    {
        DB::table('orders')->where('id', 1)->update(['note' => 'x']);
    }
PHP);

    expect($entries)->toBe([]);
});

it('still reads Schema::table when a DB::table call precedes it', function (): void {
    $entries = scan(<<<'PHP'
    public function up(): void
    {
        DB::table('orders')->where('id', 1)->update(['note' => 'x']);

        Schema::table('orders', function (Blueprint $table) {
            $table->string('note');
        });
    }
PHP);

    expect($entries)->toHaveKey('orders.note');
});

it('ignores a static call through a variable, whose receiver is not a literal class name', function (): void {
    // $model::create(...) is a static call through a variable -- legal PHP -- and
    // its receiver tokenizes as T_VARIABLE rather than the T_STRING a literal
    // `Schema::` reference would be.
    $entries = scan(<<<'PHP'
    public function up(): void
    {
        $model::create('orders', function (Blueprint $table) {
            $table->foreignId('customer_id');
        });
    }
PHP);

    expect($entries)->toBe([]);
});

it('reads a fully-qualified Schema::create call', function (): void {
    // A fully-qualified reference tokenizes its receiver as a single
    // T_NAME_FULLY_QUALIFIED token holding the whole path, not the T_STRING a
    // bare `Schema` produces, and real migrations do call it fully qualified.
    $entries = scan(<<<'PHP'
    public function up(): void
    {
        \Illuminate\Support\Facades\Schema::create('orders', function (Blueprint $table) {
            $table->foreignId('customer_id');
        });
    }
PHP);

    expect($entries)->toHaveKeys(['orders', 'orders.customer_id']);
});

it('ignores a receiver that merely contains the word Schema', function (): void {
    // MySchema::create shares a suffix with Schema::create but is a different
    // class entirely; matching on "ends with" rather than "equals" must not be
    // fooled by that.
    $entries = scan(<<<'PHP'
    public function up(): void
    {
        MySchema::create('orders', function (Blueprint $table) {
            $table->foreignId('customer_id');
        });
    }
PHP);

    expect($entries)->toBe([]);
});

it('returns nothing for a migration that does not parse as PHP at all', function (): void {
    // TOKEN_PARSE is what makes this true: without it the tokenizer reads broken
    // source leniently and hands back tokens anyway, which is exactly the silent
    // best guess this class exists to refuse. Built directly rather than through
    // scan()'s helper above, which always wraps the body in a syntactically valid
    // class -- the whole point here is a file that never closes.
    expect((new MigrationScanner)->scan('<?php class {'))->toBe([]);
});

it('returns nothing for a migration that parses but does not compile', function (): void {
    // This parses fine -- it is only illegal once PHP tries to compile it, which
    // TOKEN_PARSE raises as a bare CompileError rather than the ParseError a
    // syntax error would raise. ParseError extends CompileError, so catching the
    // parent has to be what scan() does, or a file like this would escape as an
    // uncaught fatal instead of yielding nothing.
    expect((new MigrationScanner)->scan('<?php abstract final class C {}'))->toBe([]);
});
