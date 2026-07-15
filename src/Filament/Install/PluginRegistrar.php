<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Filament\Install;

/**
 * Adds Vacuum's plugin to a Filament panel provider by editing its source.
 *
 * The one dangerous thing the installer does is write to a file the developer owns,
 * so it does it by parsing rather than by matching text: PHP's own tokenizer finds
 * the `return $panel` chain and the semicolon that ends it, and the plugin call is
 * spliced in immediately before that semicolon. Strings and comments are whole
 * tokens to the tokenizer, so a semicolon inside one, or a `->plugins([...])` array
 * already in the chain, cannot be mistaken for the end of the statement.
 *
 * When the source is not a shape it recognises, it returns null rather than a guess,
 * and the installer prints instructions instead of writing anything.
 */
final class PluginRegistrar
{
    /** The registration this splices in, fully qualified so no import is needed. */
    public const string PLUGIN_CALL = '->plugin(\Heyosseus\Vacuum\Filament\VacuumPlugin::make())';

    /**
     * Return the source with Vacuum's plugin registered, or null if it cannot be
     * done safely. Source that already registers the plugin is returned unchanged.
     */
    public function inject(string $contents): ?string
    {
        // Already registered: idempotent, so re-running the installer is harmless.
        if (str_contains($contents, 'VacuumPlugin')) {
            return $contents;
        }

        /** @var list<array{0: int, 1: string, 2: int}|string> $tokens */
        $tokens = token_get_all($contents);

        $return = $this->locateReturnPanel($tokens);

        if ($return === null) {
            return null;
        }

        return $this->spliceBeforeTerminator($tokens, $return);
    }

    /**
     * The index of the `$panel` variable in a `return $panel` statement, or null if
     * there is no such statement to hook the chain onto.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private function locateReturnPanel(array $tokens): ?int
    {
        foreach ($tokens as $index => $token) {
            if (! is_array($token)) {
                continue;
            }
            if ($token[0] !== T_RETURN) {
                continue;
            }
            $next = $this->skipWhitespace($tokens, $index + 1);
            $following = $tokens[$next] ?? null;

            if (is_array($following) && $following[0] === T_VARIABLE && $following[1] === '$panel') {
                return $next;
            }
        }

        return null;
    }

    /**
     * Rebuild the source with the plugin call inserted before the semicolon that
     * closes the return statement beginning at $returnIndex. Returns null if that
     * semicolon cannot be found at the top level of the chain.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private function spliceBeforeTerminator(array $tokens, int $returnIndex): ?string
    {
        $depth = 0;
        $indent = null;
        $count = count($tokens);

        for ($index = $returnIndex + 1; $index < $count; $index++) {
            $token = $tokens[$index];

            if (is_array($token)) {
                // The indentation of the chain is whatever sits before its first
                // arrow, so the inserted line lines up with the calls around it.
                if ($token[0] === T_OBJECT_OPERATOR && $depth === 0 && $indent === null) {
                    $indent = $this->indentBefore($tokens, $index);
                }

                continue;
            }

            if (in_array($token, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($token, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($token === ';' && $depth === 0) {
                return $this->rebuild($tokens, $index, $indent);
            }
        }

        return null;
    }

    /**
     * The token stream is a lossless copy of the source, so emitting it verbatim and
     * dropping the plugin call in front of the terminator changes nothing else.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private function rebuild(array $tokens, int $terminatorIndex, ?string $indent): string
    {
        $insertion = $indent === null
            ? self::PLUGIN_CALL
            : "\n".$indent.self::PLUGIN_CALL;

        $source = '';

        foreach ($tokens as $index => $token) {
            if ($index === $terminatorIndex) {
                $source .= $insertion;
            }

            $source .= is_array($token) ? $token[1] : $token;
        }

        return $source;
    }

    /**
     * The indentation on the line the given token sits on, or null when the token
     * does not begin its own line and the chain is written inline.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private function indentBefore(array $tokens, int $index): ?string
    {
        $previous = $tokens[$index - 1] ?? null;

        if (is_array($previous) && $previous[0] === T_WHITESPACE && str_contains($previous[1], "\n")) {
            return substr($previous[1], strrpos($previous[1], "\n") + 1);
        }

        return null;
    }

    /**
     * The index of the next token that is not whitespace.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private function skipWhitespace(array $tokens, int $index): int
    {
        $count = count($tokens);

        while ($index < $count) {
            $token = $tokens[$index];

            if (! is_array($token) || $token[0] !== T_WHITESPACE) {
                break;
            }

            $index++;
        }

        return $index;
    }
}
