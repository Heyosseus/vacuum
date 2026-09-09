<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Schema;

use CompileError;

/**
 * Reads one migration and reports where each table and column was declared.
 *
 * By tokenizing rather than by matching text, for the same reason the Filament
 * installer does: PHP's own tokenizer knows a string from a comment from code,
 * so a `Schema::create(` inside a docblock is not mistaken for a declaration and
 * a brace inside a string does not close a scope.
 *
 * It is deliberately conservative and it declines rather than guesses. A table
 * name that is a variable yields nothing at all. A `DB::table('orders')` in a
 * data-backfill migration yields nothing either: only Schema opens a scope, so a
 * receiver that merely shares a method name never registers a table it did not
 * declare. A file PHP cannot compile yields nothing at all, rather than whatever
 * the tokenizer's lenient best guess at broken source happens to produce. An
 * anchor pointing at the wrong line, or the wrong table, is worse
 * than no anchor: the reader trusts it, opens the file, and finds something
 * unrelated.
 */
final class MigrationScanner
{
    /** Methods that create more than the one column they are named for. */
    private const array MORPHS = ['morphs', 'nullableMorphs', 'uuidMorphs', 'nullableUuidMorphs', 'ulidMorphs', 'nullableUlidMorphs'];

    /**
     * @return array<string, int> Keys are "table" and "table.column"; values are 1-based lines.
     */
    public function scan(string $source): array
    {
        try {
            // TOKEN_PARSE is what makes a genuinely broken migration yield
            // nothing rather than whatever the tokenizer's lenient best-effort
            // reading of invalid source happens to produce.
            /** @var list<array{0: int, 1: string, 2: int}|string> $tokens */
            $tokens = @token_get_all($source, TOKEN_PARSE);
        } catch (CompileError) {
            // ParseError extends CompileError, so this also catches source that
            // merely fails to parse; a valid parse can still fail to compile, such
            // as `abstract final class C {}`, and that has to be caught too.
            return [];
        }

        $entries = [];
        $table = null;
        $depth = 0;
        $scope = 0;

        foreach ($tokens as $index => $token) {
            if ($token === '{') {
                $depth++;

                continue;
            }

            if ($token === '}') {
                $depth--;

                if ($table !== null && $depth < $scope) {
                    $table = null;
                }

                continue;
            }

            if (! is_array($token)) {
                continue;
            }

            $opened = $this->schemaCall($tokens, $index);

            if ($opened !== null) {
                $table = $opened[0];
                $scope = $depth + 1;
                $entries[$table] ??= $opened[1];

                continue;
            }

            if ($table === null) {
                continue;
            }

            if ($token[0] !== T_OBJECT_OPERATOR) {
                continue;
            }

            foreach ($this->columns($tokens, $index) as $column) {
                $entries[$table.'.'.$column] ??= $token[2];
            }
        }

        return $entries;
    }

    /**
     * The table a `Schema::create('x', ...)` or `Schema::table('x', ...)` opens,
     * with the line it sits on, or null if this is not one.
     *
     * The receiver has to be checked, not just the method name: `DB::table('x')`
     * opens no schema scope at all -- it runs a query -- but shares the name
     * `table` with the call that actually declares columns. Without this check a
     * data-backfill migration's `DB::table('orders')` would open an `orders`
     * scope of its own, and every `$var->method('literal')` after it in the same
     * method body would register as a column of a table this migration never
     * touched.
     *
     * A bare `Schema` is not the only way this receiver tokenizes, though: a
     * fully-qualified call such as `\Illuminate\Support\Facades\Schema::create`
     * tokenizes its receiver as a single T_NAME_FULLY_QUALIFIED token holding the
     * whole path, and an aliased import can shorten or lengthen that path
     * further, so both a fully- and a partially-qualified name are accepted as
     * long as they end with `\Schema`. A name that merely contains the word,
     * such as `MySchema`, is not accepted -- that is a different class that only
     * happens to share a suffix.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     * @return array{0: string, 1: int}|null
     */
    private function schemaCall(array $tokens, int $index): ?array
    {
        $token = $tokens[$index];

        if (! is_array($token) || $token[0] !== T_DOUBLE_COLON) {
            return null;
        }

        $receiver = $tokens[$index - 1] ?? null;

        if (! is_array($receiver) || ! $this->isSchemaReceiver($receiver)) {
            return null;
        }

        $method = $tokens[$index + 1] ?? null;

        if (! is_array($method) || ! in_array($method[1], ['create', 'table'], true)) {
            return null;
        }

        $name = $tokens[$index + 3] ?? null;

        if (! is_array($name) || $name[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        return [trim($name[1], "'\""), $method[2]];
    }

    /**
     * Whether a token naming the left side of a `::` is some spelling of the
     * `Schema` class: a bare `T_STRING` for an unqualified reference, or a
     * `T_NAME_FULLY_QUALIFIED` / `T_NAME_QUALIFIED` whose path ends with
     * `\Schema` for a fully- or partially-qualified one.
     *
     * @param  array{0: int, 1: string, 2: int}  $receiver
     */
    private function isSchemaReceiver(array $receiver): bool
    {
        if ($receiver[0] === T_STRING) {
            return $receiver[1] === 'Schema';
        }

        if ($receiver[0] === T_NAME_FULLY_QUALIFIED || $receiver[0] === T_NAME_QUALIFIED) {
            return str_ends_with($receiver[1], '\\Schema');
        }

        return false;
    }

    /**
     * The column names a `->method('x')` call creates, which is usually one and
     * for a morphs pair is two.
     *
     * A chained call -- `$table->foreignId('customer_id')->constrained('users')`
     * -- has an object operator too, and its receiver is `foreignId()`'s return
     * value rather than the Blueprint. Treating 'users' as a column of orders
     * would invent one the table does not have, so the receiver is checked
     * before anything else here is even asked. The Blueprint's own parameter is
     * conventionally named $table, but this accepts any variable rather than
     * that one name specifically: matching a particular name would still be a
     * guess, and this class declines rather than guesses.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     * @return list<string>
     */
    private function columns(array $tokens, int $index): array
    {
        if (! $this->hasVariableReceiver($tokens, $index)) {
            return [];
        }

        $method = $tokens[$index + 1] ?? null;

        if (! is_array($method) || $method[0] !== T_STRING) {
            return [];
        }

        $argument = $tokens[$index + 3] ?? null;

        if (! is_array($argument) || $argument[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return [];
        }

        $name = trim($argument[1], "'\"");

        if (in_array($method[1], self::MORPHS, true)) {
            return [$name.'_type', $name.'_id'];
        }

        return [$name];
    }

    /**
     * Whether the token immediately before an object operator -- skipping
     * whitespace, since `$table ->foreignId('x')` is legal PHP -- is a
     * variable. True for `$table->foreignId(...)`; false for the `)` a chained
     * call's arrow actually follows.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private function hasVariableReceiver(array $tokens, int $index): bool
    {
        $previous = $index - 1;

        while ($previous >= 0 && is_array($tokens[$previous]) && $tokens[$previous][0] === T_WHITESPACE) {
            $previous--;
        }

        return $previous >= 0 && is_array($tokens[$previous]) && $tokens[$previous][0] === T_VARIABLE;
    }
}
