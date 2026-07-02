<?php

namespace Amims71\TinkerWeb\Runner;

/** Splits a PHP snippet into top-level statements and reports bracket balance, using token_get_all. */
final class StatementSplitter
{
    /**
     * Split a snippet into top-level statements, cutting only at a ';' at bracket-depth 0.
     * A trailing expression without ';' becomes the final statement. A '}' is never a cut
     * point (it is ambiguous: closure/else/match bodies), so if/else, try/catch and closure
     * assignments stay whole. Separators inside strings/comments/heredocs are ignored.
     *
     * @return string[]
     */
    public static function split(string $code): array
    {
        $tokens = @token_get_all('<?php '.$code); // no TOKEN_PARSE: must not throw on a trailing no-';' expr
        array_shift($tokens);                     // drop the injected open tag

        $statements = [];
        $buffer = '';
        $depth = 0;

        foreach ($tokens as $token) {
            $buffer .= is_array($token) ? $token[1] : $token;

            if (! is_array($token)) {
                if ($token === '{' || $token === '(' || $token === '[') {
                    $depth++;
                } elseif ($token === '}' || $token === ')' || $token === ']') {
                    $depth--;
                } elseif ($token === ';' && $depth === 0) {
                    $trimmed = trim($buffer);
                    if ($trimmed !== '' && $trimmed !== ';') {
                        $statements[] = $trimmed;
                    }
                    $buffer = '';
                }
            }
        }

        $trailing = trim($buffer);
        if ($trailing !== '') {
            $statements[] = $trailing;
        }

        return $statements;
    }

    /** True when every '{', '(' and '[' is closed — a cheap "input looks complete" check. */
    public static function isBalanced(string $code): bool
    {
        $depth = 0;

        foreach (@token_get_all('<?php '.$code) as $token) {
            if (is_array($token)) {
                continue;
            }
            if ($token === '{' || $token === '(' || $token === '[') {
                $depth++;
            } elseif ($token === '}' || $token === ')' || $token === ']') {
                $depth--;
            }
        }

        return $depth === 0;
    }
}
