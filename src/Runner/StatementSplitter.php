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
            $depth += self::depthDelta($token);

            if ($token === ';' && $depth === 0) {
                $trimmed = trim($buffer);
                if ($trimmed !== '' && $trimmed !== ';') {
                    $statements[] = $trimmed;
                }
                $buffer = '';
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
            $depth += self::depthDelta($token);
        }

        return $depth === 0;
    }

    /**
     * Bracket-depth change for one token. Some openers are compound tokens whose matching
     * closer is a plain char (interpolation '{$x}', '${x}', and '#[' attributes), so they
     * must be counted here or depth desyncs against the plain '}'/']' that closes them.
     */
    private static function depthDelta(array|string $token): int
    {
        if (is_array($token)) {
            return match ($token[0]) {
                T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE => 1,
                default => 0,
            };
        }

        return match ($token) {
            '{', '(', '[' => 1,
            '}', ')', ']' => -1,
            default => 0,
        };
    }
}
