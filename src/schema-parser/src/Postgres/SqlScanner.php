<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser\Postgres;

use Hypervel\SchemaParser\ParseException;

use function preg_match;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function trim;

/**
 * Low-level, quote-aware scanning over raw SQL text.
 *
 * Every method here exists because the naive version is wrong on real dumps: a
 * DEFAULT of '--' is not a comment, a function body between dollar quotes is
 * not SQL to be scanned for parentheses, and a column named "a,b" does not end
 * a column definition.
 *
 * @internal
 */
class SqlScanner
{
    /**
     * Advance past whatever quoted or commented run starts at $i.
     *
     * Returns the index of the last character of that run, so a `for` loop that
     * increments afterwards lands on the next unquoted character. When $i is not
     * the start of such a run, $i is returned unchanged.
     */
    public static function skip(string $sql, int $i): int
    {
        $length = strlen($sql);
        $char = $sql[$i];

        if ($char === "'") {
            return self::skipSingleQuoted($sql, $i);
        }

        if ($char === '"') {
            return self::skipDoubleQuoted($sql, $i);
        }

        if ($char === '$') {
            return self::skipDollarQuoted($sql, $i);
        }

        if ($char === '-' && ($sql[$i + 1] ?? '') === '-') {
            $end = strpos($sql, "\n", $i);

            return $end === false ? $length - 1 : $end - 1;
        }

        if ($char === '/' && ($sql[$i + 1] ?? '') === '*') {
            return self::skipBlockComment($sql, $i);
        }

        return $i;
    }

    /**
     * Skip a single-quoted literal.
     *
     * Native dumps double quotes even with standard_conforming_strings off,
     * so only E-prefixed literals need backslash-aware boundary scanning.
     */
    private static function skipSingleQuoted(string $sql, int $i): int
    {
        $length = strlen($sql);
        $escaping = $i > 0 && strtolower($sql[$i - 1]) === 'e'
            && ($i === 1 || ! self::isIdentifierPart($sql[$i - 2]));

        for ($j = $i + 1; $j < $length; ++$j) {
            if ($escaping && $sql[$j] === '\\') {
                ++$j;
                continue;
            }

            if ($sql[$j] !== "'") {
                continue;
            }

            if (($sql[$j + 1] ?? '') === "'") {
                ++$j;
                continue;
            }

            return $j;
        }

        throw self::syntaxError('Unterminated string literal', $sql, $i);
    }

    /**
     * Skip a quoted identifier, preserving doubled quotes.
     */
    private static function skipDoubleQuoted(string $sql, int $i): int
    {
        $length = strlen($sql);

        for ($j = $i + 1; $j < $length; ++$j) {
            if ($sql[$j] !== '"') {
                continue;
            }

            if (($sql[$j + 1] ?? '') === '"') {
                ++$j;
                continue;
            }

            return $j;
        }

        throw self::syntaxError('Unterminated quoted identifier', $sql, $i);
    }

    /**
     * Skip a dollar-quoted literal.
     *
     * Dollar quoting - $$ ... $$ or $tag$ ... $tag$ - is how pg_dump writes
     * function bodies. The body is arbitrary text, frequently containing
     * semicolons and unbalanced parentheses, so it must be skipped whole.
     */
    private static function skipDollarQuoted(string $sql, int $i): int
    {
        // A dollar sign inside an unquoted identifier cannot start a quoted body.
        if (($i > 0 && self::isIdentifierPart($sql[$i - 1]))
            || preg_match('/\G\$([A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)?\$/', $sql, $matches, 0, $i) !== 1) {
            return $i;
        }

        $tag = $matches[0];
        $end = strpos($sql, $tag, $i + strlen($tag));

        if ($end === false) {
            throw self::syntaxError('Unterminated dollar-quoted literal', $sql, $i);
        }

        return $end + strlen($tag) - 1;
    }

    /**
     * Skip a block comment, including PostgreSQL's nested comments.
     */
    private static function skipBlockComment(string $sql, int $i): int
    {
        $length = strlen($sql);
        $depth = 0;

        for ($j = $i; $j < $length - 1; ++$j) {
            if ($sql[$j] === '/' && $sql[$j + 1] === '*') {
                ++$depth;
                ++$j;
                continue;
            }

            if ($sql[$j] !== '*' || $sql[$j + 1] !== '/') {
                continue;
            }

            --$depth;
            ++$j;

            if ($depth === 0) {
                return $j;
            }
        }

        throw self::syntaxError('Unterminated block comment', $sql, $i);
    }

    /**
     * Return the contents between the parenthesis at $open and its match, or null when
     * the text ends first.
     */
    public static function balanced(string $sql, int $open): ?string
    {
        $length = strlen($sql);
        $depth = 0;

        for ($i = $open; $i < $length; ++$i) {
            $i += strcspn($sql, "'\"$-/()", $i);
            if ($i === $length) {
                break;
            }
            $skipped = self::skip($sql, $i);

            if ($skipped !== $i) {
                $i = $skipped;
                continue;
            }

            if ($sql[$i] === '(') {
                ++$depth;
            } elseif ($sql[$i] === ')') {
                --$depth;

                if ($depth === 0) {
                    return substr($sql, $open + 1, $i - $open - 1);
                }
            }
        }

        return null;
    }

    /**
     * Split on commas that are not inside groups or quotes.
     *
     * @return list<string>
     */
    public static function splitTopLevel(string $sql): array
    {
        $parts = [];
        $depth = 0;
        $start = 0;
        $length = strlen($sql);

        for ($i = 0; $i < $length; ++$i) {
            $i += strcspn($sql, "'\"$-/()[],", $i);
            if ($i === $length) {
                break;
            }
            $skipped = self::skip($sql, $i);

            if ($skipped !== $i) {
                $i = $skipped;
                continue;
            }

            $char = $sql[$i];

            if ($char === '(' || $char === '[') {
                ++$depth;
            } elseif ($char === ')' || $char === ']') {
                --$depth;
                if ($depth < 0) {
                    throw self::syntaxError('Unexpected closing group', $sql, $i);
                }
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = trim(substr($sql, $start, $i - $start));
                $start = $i + 1;
            }
        }

        if ($depth !== 0) {
            throw new ParseException('Unterminated group');
        }

        $last = trim(substr($sql, $start));

        if ($last !== '') {
            $parts[] = $last;
        }

        return $parts;
    }

    /**
     * Determine whether a byte can continue an unquoted PostgreSQL identifier.
     */
    public static function isIdentifierPart(string $character): bool
    {
        return ($character >= 'a' && $character <= 'z')
            || ($character >= 'A' && $character <= 'Z')
            || ($character >= '0' && $character <= '9')
            || $character === '_'
            || $character === '$'
            || ord($character) >= 128;
    }

    /**
     * Resolve an identifier to the name PostgreSQL stores.
     *
     * Quoted identifiers keep their case and lose their doubled quotes;
     * unquoted ones are folded to lower case.
     */
    public static function identifier(string $identifier): string
    {
        if (str_starts_with($identifier, '"')) {
            return str_replace('""', '"', substr($identifier, 1, -1));
        }

        return strtolower($identifier);
    }

    /**
     * Locate a parse failure without counting lines during successful scans.
     */
    private static function syntaxError(string $message, string $sql, int $offset): ParseException
    {
        return new ParseException($message, substr_count($sql, "\n", 0, $offset) + 1);
    }
}
