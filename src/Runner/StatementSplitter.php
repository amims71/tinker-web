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

    /** True when a top-level statement is a `use` import or `namespace` declaration. */
    public static function isDeclaration(string $statement): bool
    {
        return in_array(self::firstToken($statement), [T_USE, T_NAMESPACE], true);
    }

    /**
     * Text to replay before later eval()s for a use/namespace declaration — they do not carry
     * across separate eval() compilation units. Returns the trimmed source (with '; ') for an
     * effective declaration (a namespace, or a compound/aliased use), or '' for a no-effect
     * declaration (a bare global `use Foo;`) or a non-declaration. A no-effect `use` is skipped
     * deliberately: replaying it would emit a "has no effect" warning that the runner turns into
     * an exception.
     */
    public static function preambleFor(string $statement): string
    {
        $first = self::firstToken($statement);

        if ($first === T_NAMESPACE) {
            return rtrim(trim($statement), ';').'; ';
        }
        if ($first !== T_USE) {
            return '';
        }

        foreach (token_get_all('<?php '.$statement) as $token) {
            if (is_array($token)
                && in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR, T_AS], true)) {
                return rtrim(trim($statement), ';').'; ';
            }
        }

        return '';
    }

    /** First significant token id (skipping the open tag and whitespace/comments), or null. */
    private static function firstToken(string $statement): ?int
    {
        foreach (token_get_all('<?php '.$statement) as $i => $token) {
            if ($i === 0) {
                continue;
            }
            if (! is_array($token)) {
                return null;
            }
            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $token[0];
        }

        return null;
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
