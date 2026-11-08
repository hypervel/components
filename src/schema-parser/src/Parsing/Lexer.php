<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser\Parsing;

use Hypervel\SchemaParser\ParseException;

/**
 * Split SQL into statements and tokens located in the original source.
 *
 * Reading and skipping share one scanner. Tokens are only created as a parser
 * reads them, so the unread remainder of a statement, and the body of a group
 * whose source text is all a parser needs, cost a scan.
 *
 * @internal
 */
abstract class Lexer
{
    /** Bytes of multibyte characters, which unquoted identifiers may contain. */
    protected const string HIGH_BYTES = "\x80\x81\x82\x83\x84\x85\x86\x87\x88\x89\x8a\x8b\x8c\x8d\x8e\x8f"
        . "\x90\x91\x92\x93\x94\x95\x96\x97\x98\x99\x9a\x9b\x9c\x9d\x9e\x9f"
        . "\xa0\xa1\xa2\xa3\xa4\xa5\xa6\xa7\xa8\xa9\xaa\xab\xac\xad\xae\xaf"
        . "\xb0\xb1\xb2\xb3\xb4\xb5\xb6\xb7\xb8\xb9\xba\xbb\xbc\xbd\xbe\xbf"
        . "\xc0\xc1\xc2\xc3\xc4\xc5\xc6\xc7\xc8\xc9\xca\xcb\xcc\xcd\xce\xcf"
        . "\xd0\xd1\xd2\xd3\xd4\xd5\xd6\xd7\xd8\xd9\xda\xdb\xdc\xdd\xde\xdf"
        . "\xe0\xe1\xe2\xe3\xe4\xe5\xe6\xe7\xe8\xe9\xea\xeb\xec\xed\xee\xef"
        . "\xf0\xf1\xf2\xf3\xf4\xf5\xf6\xf7\xf8\xf9\xfa\xfb\xfc\xfd\xfe\xff";

    protected const string LETTERS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ_';

    protected const string DIGITS = '0123456789';

    protected const string WHITESPACE = " \t\n\r\f\v";

    /** Bytes that can begin a comment, so skipComment() is only called where one may start. */
    protected const array COMMENT_STARTS = ['-' => true, '/' => true];

    /** The most source that runs are matched against at once. */
    private const int WINDOW_LENGTH = 8192;

    /** The bytes a window can be cut before, as no unquoted token contains them. */
    private const string WINDOW_CUTS = " \t\n\r,()";

    protected readonly int $length;

    protected int $position = 0;

    protected int $statementStart = 0;

    /** The start of the token or terminator most recently scanned. */
    protected int $tokenStart = 0;

    protected bool $ended = true;

    /** The upper-case source from $windowStart, cut where no unquoted token can be split, that runs are matched against. */
    private string $window = '';

    protected int $windowStart = 0;

    /** Where the next window begins, past any chunk without a cut that the scanner reads instead. */
    private int $windowEnd = 0;

    /**
     * Create a lexer over the complete SQL input.
     */
    public function __construct(public readonly string $sql)
    {
        $this->length = strlen($sql);
    }

    /**
     * Begin the next non-empty statement, or return null at the end of the input.
     *
     * @return null|TokenStream<static>
     */
    public function nextStatement(): ?TokenStream
    {
        if (! $this->ended) {
            $this->skipStatement();
        }

        while (true) {
            $this->skipWhitespace();

            if ($this->position >= $this->length) {
                return null;
            }

            $terminator = $this->terminator();

            if ($terminator > 0) {
                $this->position += $terminator;
            } elseif (! $this->skipCommand()) {
                break;
            }
        }

        $this->ended = false;
        $this->statementStart = $this->position;
        $this->startStatement();

        return new TokenStream($this, $this->position);
    }

    /**
     * Append the next tokens of the current statement, through its terminator at most.
     *
     * Each token is appended with its start offset and upper-case source text;
     * a token scanned on its own also records its type. A terminator has an
     * empty text and its end recorded, as its span can include COPY data.
     *
     * @param array<int, TokenType> $types
     * @param list<int> $starts
     * @param array<int, int> $ends
     * @param list<string> $texts
     */
    public function read(array &$types, array &$starts, array &$ends, array &$texts): void
    {
        $type = $this->scan();
        $index = count($texts);
        $types[$index] = $type;
        $starts[] = $this->tokenStart;

        if ($type === TokenType::End) {
            $ends[$index] = $this->position;
            $texts[] = '';
        } else {
            $texts[] = strtoupper(substr($this->sql, $this->tokenStart, $this->position - $this->tokenStart));
        }
    }

    /**
     * Get the type of a token appended without one, which follows from its upper-case text.
     *
     * Tokens whose type depends on the lexer's state, such as string literals, always have it recorded.
     */
    abstract public function type(string $text): TokenType;

    /**
     * Match a run of tokens at the position, or return null where the scanner must read the next token.
     *
     * Dialects read runs of ordinary tokens with one native match each, so only
     * tokens that need boundary handling go through the scanner. A statement's
     * first token is matched alone, as parsers skip most statements after it.
     *
     * @return null|non-empty-list<array{string, int}> the upper-case texts and their offsets from $windowStart
     */
    protected function matchRun(string $pattern): ?array
    {
        // Matching an upper-case copy gives texts that need no conversion; copying a bounded window at a time bounds its memory.
        if ($this->position >= $this->windowEnd) {
            $chunk = substr($this->sql, $this->position, self::WINDOW_LENGTH);
            $this->windowStart = $this->position;
            $this->windowEnd = $this->position + strlen($chunk);

            // Cutting before whitespace, a comma or a parenthesis splits no unquoted token; a quoted one that is cut stops the run and is scanned.
            $cut = $this->windowEnd < $this->length ? max(0, strlen($chunk) - 1 - strcspn(strrev($chunk), self::WINDOW_CUTS)) : strlen($chunk);
            $this->window = strtoupper(substr($chunk, 0, $cut));

            if ($cut > 0) {
                $this->windowEnd = $this->position + $cut;
            }
        }

        $offset = $this->position - $this->windowStart;

        // A chunk without a cut is scanned token by token through to its end.
        if ($offset >= strlen($this->window)) {
            return null;
        }

        $first = $this->position === $this->statementStart;
        $found = $first
            ? preg_match($pattern, $this->window, $matches, PREG_OFFSET_CAPTURE, $offset)
            : preg_match_all($pattern, $this->window, $matches, PREG_OFFSET_CAPTURE, $offset);

        if ($found === false) {
            throw $this->error(preg_last_error_msg(), $this->position);
        }

        if ($found === 0) {
            return null;
        }

        return $first ? [$matches[0]] : $matches[0];
    }

    /**
     * Skip the rest of the current statement and return the offsets of its terminator.
     *
     * @return array{int, int}
     */
    public function skipStatement(): array
    {
        if (! $this->ended) {
            $this->skip();
        }

        return [$this->tokenStart, $this->position];
    }

    /**
     * Skip to the parenthesis that closes the given number of open groups and return its start.
     *
     * Returns null when the statement ends first.
     */
    public function skipGroup(int $depth): ?int
    {
        while ($this->scan() !== TokenType::End) {
            $byte = $this->sql[$this->tokenStart];

            if ($byte === '(') {
                ++$depth;
            } elseif ($byte === ')' && --$depth === 0) {
                return $this->tokenStart;
            }
        }

        return null;
    }

    /**
     * Decode a token's value.
     */
    public function value(TokenType $type, int $start, int $end): string
    {
        return match ($type) {
            TokenType::Word => $this->word($start, $end),
            TokenType::QuotedIdentifier => $this->identifier($start, $end),
            TokenType::String, TokenType::EscapedString => $this->string($type, $start, $end),
            default => substr($this->sql, $start, $end - $start),
        };
    }

    /**
     * Get the one-based line containing a source offset.
     */
    public function line(int $offset): int
    {
        return substr_count($this->sql, "\n", 0, $offset) + 1;
    }

    /**
     * Create a parse error located at a source offset.
     */
    public function error(string $message, int $offset): ParseException
    {
        return new ParseException($message, $this->line($offset));
    }

    /**
     * Scan one token or the statement terminator.
     */
    protected function scan(): TokenType
    {
        $this->skipWhitespace();
        $this->tokenStart = $this->position;

        $type = $this->position < $this->length ? $this->scanToken() : TokenType::End;

        if ($type === TokenType::End) {
            $this->finishStatement();
        }

        return $type;
    }

    /**
     * Mark the current statement as ended at its terminator.
     */
    protected function finishStatement(): void
    {
        $this->ended = true;
        $this->endStatement();
    }

    /**
     * Scan the token starting at the current position, or a terminator as End.
     */
    abstract protected function scanToken(): TokenType;

    /**
     * Scan to the end of the current statement.
     */
    protected function skip(): void
    {
        while ($this->scan() !== TokenType::End);
    }

    /**
     * Get the length of a statement terminator at the current position, or zero.
     */
    protected function terminator(): int
    {
        return $this->sql[$this->position] === ';' ? 1 : 0;
    }

    /**
     * Consume a client command between statements.
     */
    protected function skipCommand(): bool
    {
        return false;
    }

    /**
     * Reset state tracked within one statement.
     */
    protected function startStatement(): void
    {
    }

    /**
     * Handle input that belongs to a statement after its terminator.
     */
    protected function endStatement(): void
    {
    }

    /**
     * Skip whitespace and comments.
     */
    protected function skipWhitespace(): void
    {
        do {
            $this->position += strspn($this->sql, static::WHITESPACE, $this->position);
        } while ($this->position < $this->length && isset(static::COMMENT_STARTS[$this->sql[$this->position]]) && $this->skipComment());
    }

    /**
     * Skip a comment at the current position.
     */
    protected function skipComment(): bool
    {
        $byte = $this->sql[$this->position];
        $next = $this->sql[$this->position + 1] ?? '';

        if ($byte === '-' && $next === '-') {
            $this->skipLine();

            return true;
        }

        if ($byte === '/' && $next === '*') {
            $end = strpos($this->sql, '*/', $this->position + 2);

            if ($end === false) {
                throw $this->error('Unterminated comment', $this->position);
            }

            $this->position = $end + 2;

            return true;
        }

        return false;
    }

    /**
     * Skip to the start of the next line.
     */
    protected function skipLine(): void
    {
        $end = strpos($this->sql, "\n", $this->position);

        $this->position = $end === false ? $this->length : $end + 1;
    }

    /**
     * Scan a quoted run whose opening quote is at the position, escaped by doubling and optionally backslashes.
     */
    protected function scanQuoted(string $quote, bool $backslashes, string $description): void
    {
        $stops = $backslashes ? $quote . '\\' : $quote;
        $position = $this->position + 1;

        while (true) {
            if ($position < $this->length) {
                $position += strcspn($this->sql, $stops, $position);
            }

            if ($position >= $this->length) {
                throw $this->error("Unterminated {$description}", $this->position);
            }

            if ($this->sql[$position] === '\\') {
                $position += 2;
            } elseif (($this->sql[$position + 1] ?? '') === $quote) {
                $position += 2;
            } else {
                $this->position = $position + 1;

                return;
            }
        }
    }

    /**
     * Normalize an unquoted identifier or keyword.
     */
    protected function word(int $start, int $end): string
    {
        return substr($this->sql, $start, $end - $start);
    }

    /**
     * Decode a quoted identifier.
     */
    protected function identifier(int $start, int $end): string
    {
        $quote = $this->sql[$start];
        $body = substr($this->sql, $start + 1, $end - $start - 2);

        return $quote === '[' ? $body : str_replace($quote . $quote, $quote, $body);
    }

    /**
     * Decode a string literal whose quotes are escaped by doubling.
     */
    protected function string(TokenType $type, int $start, int $end): string
    {
        $open = $start + strcspn($this->sql, "'\"", $start);
        $quote = $this->sql[$open];

        return str_replace($quote . $quote, $quote, substr($this->sql, $open + 1, $end - $open - 2));
    }
}
