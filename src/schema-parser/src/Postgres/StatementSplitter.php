<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser\Postgres;

use Generator;
use Hypervel\SchemaParser\ParseException;

use function strlen;
use function strpos;
use function substr;
use function trim;

/**
 * Splits a dump into individual SQL statements.
 *
 * @internal
 */
class StatementSplitter
{
    /**
     * Yield statements with their original offsets, omitting comments and psql data.
     *
     * @return Generator<int, array{sql: string, offset: int}>
     */
    public function split(string $sql): Generator
    {
        $buffer = '';
        $length = strlen($sql);
        $start = 0;
        $started = false;

        for ($i = 0; $i < $length; ++$i) {
            $end = $i + strcspn($sql, "'\"$-/;\\", $i);
            if ($end > $i) {
                if (! $started) {
                    $start = $i + strspn($sql, " \t\n\r\f\v", $i, $end - $i);
                    $started = $start < $end;
                }
                $buffer .= substr($sql, $i, $end - $i);
                $i = $end;
                if ($i === $length) {
                    break;
                }
            }
            $char = $sql[$i];

            // Native psql meta-commands occupy a whole line and are not SQL.
            if ($char === '\\' && $this->atLineStart($sql, $i)) {
                $end = strpos($sql, "\n", $i);

                if ($end === false) {
                    break;
                }

                $i = $end;
                continue;
            }

            $skipped = SqlScanner::skip($sql, $i);

            if ($skipped !== $i) {
                // Comments are dropped; quoted runs are part of the statement.
                if ($this->isComment($sql, $i)) {
                    $buffer .= ' ';
                } else {
                    if (! $started) {
                        $start = $i;
                        $started = true;
                    }
                    $buffer .= substr($sql, $i, $skipped - $i + 1);
                }

                $i = $skipped;
                continue;
            }

            if ($char === ';') {
                $statement = trim($buffer);

                if ($statement !== '') {
                    // COPY records may contain SQL punctuation; only the native terminator ends them.
                    if (preg_match('/^COPY\b.*\bFROM\s+stdin$/isD', $statement)) {
                        if (preg_match('/^\\\\\.(?:\r?\n|\z)/m', $sql, $terminator, PREG_OFFSET_CAPTURE, $i + 1) !== 1) {
                            throw new ParseException('Unterminated COPY data', substr_count($sql, "\n", 0, $start) + 1);
                        }
                        $i = $terminator[0][1] + strlen($terminator[0][0]) - 1;
                    }
                    yield ['sql' => $statement, 'offset' => $start];
                }

                $buffer = '';
                $started = false;
                continue;
            }

            if (! $started) {
                $start = $i;
                $started = true;
            }
            $buffer .= $char;
        }

        $statement = trim($buffer);

        if ($statement !== '') {
            if (preg_match('/^COPY\b.*\bFROM\s+stdin$/isD', $statement)) {
                throw new ParseException('Unterminated COPY data', substr_count($sql, "\n", 0, $start) + 1);
            }
            yield ['sql' => $statement, 'offset' => $start];
        }
    }

    /**
     * Determine whether a comment begins at the current position.
     */
    private function isComment(string $sql, int $i): bool
    {
        $next = $sql[$i + 1] ?? '';

        return ($sql[$i] === '-' && $next === '-')
            || ($sql[$i] === '/' && $next === '*');
    }

    /**
     * Determine whether only whitespace precedes a meta-command on its line.
     */
    private function atLineStart(string $sql, int $i): bool
    {
        for ($j = $i - 1; $j >= 0; --$j) {
            if ($sql[$j] === "\n") {
                return true;
            }

            if ($sql[$j] !== ' ' && $sql[$j] !== "\t" && $sql[$j] !== "\r") {
                return false;
            }
        }

        return true;
    }
}
