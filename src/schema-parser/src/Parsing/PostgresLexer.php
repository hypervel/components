<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser\Parsing;

/**
 * Lex plain-text pg_dump output with psql's statement boundaries.
 *
 * psql ends a statement at a semicolon outside parentheses and outside the
 * BEGIN ... END body of a SQL-standard routine, treats backslash commands as
 * its own, and reads COPY FROM STDIN data through the `\.` line.
 *
 * @internal
 */
class PostgresLexer extends Lexer
{
    private const string IDENTIFIER_BYTES = self::LETTERS . self::DIGITS . '$' . self::HIGH_BYTES;

    private const string DOLLAR_TAG_BYTES = self::LETTERS . self::DIGITS . self::HIGH_BYTES;

    protected const array COMMENT_STARTS = ['-' => true, '/' => true, '\\' => true];

    /** The bytes where skipping must stop, as they can begin a quoted token, a comment or a boundary. */
    private const string SKIP_STOPS = '\'"$;()-/\\';

    /**
     * Whitespace and line comments before a token.
     *
     * Repetitions in the native patterns are bounded so one match stays within
     * PCRE's limits; longer input stops the run and is read by the scanner.
     */
    private const string SPACE = '(?:[ \t\n\r\f\x0B]++|--[^\r\n]*+){0,256}+';

    /** A word that is not a string prefix and does not run into a string. */
    private const string WORD = '(?![uU]&[\'"])[A-Za-z_\x80-\xff][A-Za-z0-9_$\x80-\xff]*+(?!\')';

    private const string NUMBER = '0[xXoObB][0-9A-Fa-f_]++|(?:\d[\d_]*+(?:\.(?!\.)[\d_]*+)?|\.\d[\d_]*+)(?:[eE][+-]?\d++)?';

    /** A quoted identifier whose closing quote does not begin a doubled one. */
    private const string QUOTED_IDENTIFIER = '"(?:[^"]++|""){0,256}+"(?!")';

    /** An operator, which cannot contain the start of a comment. */
    private const string OPERATOR = '(?!\/\*|--)[~!@#^&|`?+*%<>=\/-](?:[~!@#^&|`?+*%<>=]++|-(?!-)|\/(?!\*))*+';

    /** Single-byte punctuation other than parentheses. */
    private const string PUNCTUATION = '[^ \t\n\r\f\x0B;\'"$\\\A-Za-z0-9_\x80-\xff~!@#^&|`?+*%<>=\/()-]';

    /** The closing quote of a string literal, not the first of a doubled one, followed by more on its line so it cannot continue on a later one. */
    private const string STRING_END = '\'(?!\')(?=[ \t\f\x0B]*+(?!--)[^ \t\n\r\f\x0B])';

    /** A plain string literal where backslashes are ordinary characters. */
    private const string STANDARD_STRING = '\'(?:[^\']++|\'\'){0,256}+' . self::STRING_END;

    /** A plain string literal where backslashes are escapes. */
    private const string ESCAPED_STRING = '\'(?:[^\'\\\]++|\'\'|\\\[\s\S]){0,256}+' . self::STRING_END;

    /** A run of tokens and standard strings, read in one native call through a semicolon or up to a token that needs boundary handling. */
    private const string STANDARD_RUN = '/\G(?<!;)' . self::SPACE . '\K(?:' . self::WORD . '|' . self::NUMBER . '|' . self::QUOTED_IDENTIFIER . '|' . self::OPERATOR . '|' . self::PUNCTUATION . '|[();]|' . self::STANDARD_STRING . ')/';

    /** A run of tokens and escaped strings. */
    private const string ESCAPED_RUN = '/\G(?<!;)' . self::SPACE . '\K(?:' . self::WORD . '|' . self::NUMBER . '|' . self::QUOTED_IDENTIFIER . '|' . self::OPERATOR . '|' . self::PUNCTUATION . '|[();]|' . self::ESCAPED_STRING . ')/';

    /** Whitespace and line comments, matched to report where they end. */
    private const string SPACE_END = '/\G' . self::SPACE . '\K/';

    /** A number, operator or punctuation, matched to report where it ends. */
    private const string TOKEN_END = '/\G(?:' . self::NUMBER . '|' . self::OPERATOR . '|' . self::PUNCTUATION . '|[()])\K/';

    /** The letters psql records for the leading words that identify routines and COPY FROM STDIN. */
    private const array LEADING_WORDS = [
        'CREATE' => 'c',
        'OR' => 'o',
        'REPLACE' => 'r',
        'FUNCTION' => 'f',
        'PROCEDURE' => 'p',
        'COPY' => 'C',
        'FROM' => 'F',
        'STDIN' => 'S',
        'STDOUT' => 'S',
    ];

    /** The leading words of CREATE [OR REPLACE] FUNCTION and PROCEDURE, whose bodies can contain semicolons. */
    private const array ROUTINE_STARTS = ['cf' => true, 'cp' => true, 'corf' => true, 'corp' => true];

    /** The leading words that can still become a routine start. */
    private const array ROUTINE_PREFIXES = ['c' => true, 'co' => true, 'cor' => true];

    /** Whether ordinary string literals treat backslashes literally. */
    public bool $standardConformingStrings = true;

    private int $depth = 0;

    private int $beginDepth = 0;

    /** The first unquoted words outside parentheses, one letter each, as psql records them. */
    private string $leadingWords = '';

    private bool $routine = false;

    /** Whether words outside parentheses can still change the boundary state. */
    private bool $tracking = true;

    /**
     * Create a lexer, skipping a UTF-8 byte order mark as psql does.
     */
    public function __construct(string $sql)
    {
        parent::__construct($sql);

        $this->position = str_starts_with($sql, "\xEF\xBB\xBF") ? 3 : 0;
    }

    /**
     * Append a run of regular tokens, or one token that needs boundary handling.
     */
    public function read(array &$types, array &$starts, array &$ends, array &$texts): void
    {
        [$pattern, $stringType] = $this->standardConformingStrings
            ? [self::STANDARD_RUN, TokenType::String]
            : [self::ESCAPED_RUN, TokenType::EscapedString];

        $run = $this->matchRun($pattern);

        if ($run === null) {
            parent::read($types, $starts, $ends, $texts);

            return;
        }

        $base = $this->windowStart;

        foreach ($run as [$text, $start]) {
            $texts[] = $text;
            $starts[] = $start += $base;

            if ($text === '(') {
                ++$this->depth;
            } elseif ($text === ')' && $this->depth > 0) {
                --$this->depth;
            } elseif ($text[0] === "'") {
                $types[count($texts) - 1] = $stringType;
            } elseif ($this->tracking && $this->depth === 0 && $this->type($text) === TokenType::Word) {
                $this->trackWord($text);
            }
        }

        $this->position = $start + strlen($text);

        // A run stops after a semicolon, which ends the statement outside parentheses and routine bodies.
        if ($text === ';' && $this->depth === 0 && $this->beginDepth === 0) {
            $index = count($texts) - 1;
            $texts[$index] = '';
            $types[$index] = TokenType::End;
            $this->tokenStart = $start;
            $this->finishStatement();
            $ends[$index] = $this->position;
        }
    }

    /**
     * Get the type of a token read in a run, which follows from its upper-case text.
     */
    public function type(string $text): TokenType
    {
        $byte = $text[0];

        if (($byte >= 'A' && $byte <= 'Z') || $byte === '_' || $byte >= "\x80") {
            return TokenType::Word;
        }

        if (($byte >= '0' && $byte <= '9') || ($byte === '.' && isset($text[1]))) {
            return TokenType::Number;
        }

        return $byte === '"' ? TokenType::QuotedIdentifier : TokenType::Symbol;
    }

    /**
     * Skip to the parenthesis that closes the given number of open groups and return its start.
     *
     * Returns null when the statement ends first.
     */
    public function skipGroup(int $depth): ?int
    {
        return $this->skipTo($this->depth - $depth);
    }

    /**
     * Scan the token starting at the current position, or a terminator as End.
     */
    protected function scanToken(): TokenType
    {
        $sql = $this->sql;
        $byte = $sql[$this->position];

        if (($byte >= 'a' && $byte <= 'z') || ($byte >= 'A' && $byte <= 'Z') || $byte === '_' || $byte >= "\x80") {
            return $this->scanWord();
        }

        if ($byte === ';') {
            ++$this->position;

            return $this->depth === 0 && $this->beginDepth === 0 ? TokenType::End : TokenType::Symbol;
        }

        if ($byte === "'") {
            return $this->scanString($this->standardConformingStrings ? TokenType::String : TokenType::EscapedString);
        }

        if ($byte === '"') {
            $this->scanQuoted('"', false, 'quoted identifier');

            return TokenType::QuotedIdentifier;
        }

        if ($byte === '$') {
            return $this->scanDollar();
        }

        // Numbers, operators and punctuation have no boundary handling.
        $start = $this->position;
        $this->position = $this->matchEnd(self::TOKEN_END);

        if ($byte === '(') {
            ++$this->depth;
        } elseif ($byte === ')' && $this->depth > 0) {
            --$this->depth;
        }

        return ($byte >= '0' && $byte <= '9') || ($byte === '.' && $this->position > $start + 1) ? TokenType::Number : TokenType::Symbol;
    }

    /**
     * Skip to the terminator.
     */
    protected function skip(): void
    {
        $this->skipTo(null);
    }

    /**
     * Skip to the parenthesis that lowers the depth to the given one and return its start.
     *
     * Without a depth, or when the statement ends first, it skips to the
     * terminator and returns null. Native jumps reach the bytes that can
     * change boundary state.
     */
    private function skipTo(?int $depth): ?int
    {
        $sql = $this->sql;

        while (true) {
            // Words outside parentheses can begin a routine body or COPY data while psql still tracks them.
            if ($this->depth > 0 || ! $this->tracking) {
                $from = $this->position;
                $this->position += strcspn($sql, self::SKIP_STOPS, $from);
                $byte = $sql[$this->position] ?? '';

                if ($byte === '(') {
                    ++$this->depth;
                    ++$this->position;

                    continue;
                }

                if ($byte === ')') {
                    $this->depth = max(0, $this->depth - 1);

                    if ($this->depth === $depth) {
                        return $this->position++;
                    }

                    ++$this->position;

                    continue;
                }

                // A string prefix or identifier before a quote or dollar sign changes how it is read.
                if ($byte === "'" || $byte === '"' || $byte === '$') {
                    while ($this->position > $from && strspn($sql, self::IDENTIFIER_BYTES, $this->position - 1, 1) === 1) {
                        --$this->position;
                    }

                    // Continued strings join without changing the boundary, so plain quoted tokens are skipped whole.
                    if ($byte !== '$' && $sql[$this->position] === $byte) {
                        $this->scanQuoted($byte, $byte === "'" && ! $this->standardConformingStrings, $byte === "'" ? 'string literal' : 'quoted identifier');

                        continue;
                    }
                }
            }

            if ($this->scan() === TokenType::End) {
                return null;
            }

            // A parenthesis after a comment is scanned as a token.
            if ($this->depth === $depth) {
                return $this->tokenStart;
            }
        }
    }

    /**
     * Reset the boundary state tracked within one statement.
     */
    protected function startStatement(): void
    {
        $this->depth = 0;
        $this->beginDepth = 0;
        $this->leadingWords = '';
        $this->routine = false;
        $this->tracking = true;
    }

    /**
     * Skip the data of a COPY FROM STDIN statement through its `\.` line.
     */
    protected function endStatement(): void
    {
        if (($this->leadingWords[0] ?? '') !== 'C') {
            return;
        }

        // Like psql, find FROM among the words that can follow COPY, then STDIN or STDOUT.
        $from = strpos($this->leadingWords, 'F', 1);

        if ($from === false || $from > 6 || ($this->leadingWords[$from + 1] ?? '') !== 'S') {
            return;
        }

        $search = $this->position;

        while (($found = strpos($this->sql, "\n\\.", $search)) !== false) {
            $after = $found + 3;

            if ($after === $this->length || $this->sql[$after] === "\n") {
                $this->position = min($after + 1, $this->length);

                return;
            }

            if ($this->sql[$after] === "\r" && ($this->sql[$after + 1] ?? '') === "\n") {
                $this->position = $after + 2;

                return;
            }

            $search = $found + 1;
        }

        throw $this->error('Unterminated COPY data', $this->statementStart);
    }

    /**
     * Skip whitespace and comments.
     */
    protected function skipWhitespace(): void
    {
        while (true) {
            $this->position += strspn($this->sql, static::WHITESPACE, $this->position);
            $byte = $this->sql[$this->position] ?? '';

            if ($byte === '-' && ($this->sql[$this->position + 1] ?? '') === '-') {
                // Line comments usually come in blocks, which one native match skips.
                $this->position = $this->matchEnd(self::SPACE_END);
            } elseif (! isset(static::COMMENT_STARTS[$byte]) || ! $this->skipComment()) {
                return;
            }
        }
    }

    /**
     * Skip comments, including nested block comments and psql backslash commands.
     */
    protected function skipComment(): bool
    {
        $byte = $this->sql[$this->position];
        $next = $this->sql[$this->position + 1] ?? '';

        // psql reads its commands from line-feed separated input lines.
        if ($byte === '\\') {
            $this->skipLine();

            return true;
        }

        // PostgreSQL ends a line comment at a carriage return as well as a line feed.
        if ($byte === '-' && $next === '-') {
            $this->position += strcspn($this->sql, "\r\n", $this->position);

            return true;
        }

        if ($byte !== '/' || $next !== '*') {
            return false;
        }

        $depth = 1;
        $position = $this->position + 2;

        while ($depth > 0) {
            $position += strcspn($this->sql, '/*', $position);

            if ($position >= $this->length - 1) {
                throw $this->error('Unterminated comment', $this->position);
            }

            $pair = $this->sql[$position] . $this->sql[$position + 1];

            if ($pair === '/*') {
                ++$depth;
                $position += 2;
            } elseif ($pair === '*/') {
                --$depth;
                $position += 2;
            } else {
                ++$position;
            }
        }

        $this->position = $position;

        return true;
    }

    /**
     * Fold an unquoted identifier or keyword to lower case.
     */
    protected function word(int $start, int $end): string
    {
        return strtolower(substr($this->sql, $start, $end - $start));
    }

    /**
     * Decode a quoted identifier, including Unicode escapes.
     */
    protected function identifier(int $start, int $end): string
    {
        if ($this->sql[$start] !== '"') {
            [$body, $escape] = $this->unicodeParts($start, $end, '"');

            return $this->decodeUnicode(str_replace('""', '"', $body), $escape, $start);
        }

        return parent::identifier($start, $end);
    }

    /**
     * Decode any PostgreSQL string literal form.
     */
    protected function string(TokenType $type, int $start, int $end): string
    {
        $byte = $this->sql[$start];

        if ($byte === '$') {
            $tag = strpos($this->sql, '$', $start + 1) - $start + 1;

            return substr($this->sql, $start + $tag, $end - $start - 2 * $tag);
        }

        if ($byte === 'u' || $byte === 'U') {
            [$body, $escape] = $this->unicodeParts($start, $end, "'");

            return $this->decodeUnicode(str_replace("''", "'", $body), $escape, $start);
        }

        $escaped = $type === TokenType::EscapedString;
        $value = '';

        foreach ($this->segments($byte === "'" ? $start : $start + 1, $end, $escaped)[0] as $segment) {
            $value .= $escaped ? $this->unescape($segment, $start) : str_replace("''", "'", $segment);
        }

        return $value;
    }

    /**
     * Get the end of a pattern's match at the current position, which the pattern reports with \K.
     */
    private function matchEnd(string $pattern): int
    {
        if (preg_match($pattern, $this->sql, $matches, PREG_OFFSET_CAPTURE, $this->position) === false) {
            throw $this->error(preg_last_error_msg(), $this->position);
        }

        return $matches[0][1];
    }

    /**
     * Scan an identifier, keyword or prefixed literal.
     */
    private function scanWord(): TokenType
    {
        $start = $this->position;
        $this->position += 1 + strspn($this->sql, self::IDENTIFIER_BYTES, $start + 1);
        $next = $this->sql[$this->position] ?? '';

        if ($this->position === $start + 1) {
            $prefix = $this->sql[$start];

            $type = $next === "'" ? match ($prefix) {
                'e', 'E' => TokenType::EscapedString,
                'b', 'B', 'x', 'X' => TokenType::String,
                'n', 'N' => $this->standardConformingStrings ? TokenType::String : TokenType::EscapedString,
                default => null,
            } : null;

            if ($type !== null) {
                return $this->scanString($type);
            }

            if (($prefix === 'u' || $prefix === 'U') && $next === '&') {
                $quote = $this->sql[$this->position + 1] ?? '';

                if ($quote === "'" || $quote === '"') {
                    ++$this->position;
                    $type = $quote === "'"
                        ? $this->scanString(TokenType::String)
                        : $this->scanIdentifier();
                    $this->scanUnicodeEscape();

                    return $type;
                }
            }
        }

        if ($this->depth === 0 && $this->tracking) {
            $this->trackWord(strtoupper(substr($this->sql, $start, $this->position - $start)));
        }

        return TokenType::Word;
    }

    /**
     * Track a word outside parentheses that psql uses to find routine bodies and COPY FROM STDIN statements.
     */
    private function trackWord(string $word): void
    {
        if ($this->routine) {
            if ($word === 'BEGIN' || ($this->beginDepth > 0 && $word === 'CASE')) {
                ++$this->beginDepth;
            } elseif ($this->beginDepth > 0 && $word === 'END') {
                --$this->beginDepth;
            }

            return;
        }

        $this->leadingWords .= self::LEADING_WORDS[$word] ?? '-';
        $leading = $this->leadingWords;
        $this->routine = isset(self::ROUTINE_STARTS[$leading]);

        // psql records eight words, but later ones only matter while COPY FROM STDIN or a routine is still possible.
        $this->tracking = $this->routine || ($leading[0] === 'C' ? strlen($leading) < 8 : isset(self::ROUTINE_PREFIXES[$leading]));
    }

    /**
     * Scan a quoted identifier.
     */
    private function scanIdentifier(): TokenType
    {
        $this->scanQuoted('"', false, 'quoted identifier');

        return TokenType::QuotedIdentifier;
    }

    /**
     * Scan a quoted string and the segments that continue it on later lines.
     */
    private function scanString(TokenType $type): TokenType
    {
        do {
            $this->scanQuoted("'", $type === TokenType::EscapedString, 'string literal');
            $next = $this->continuation($this->position);

            if ($next !== null) {
                $this->position = $next;
            }
        } while ($next !== null);

        return $type;
    }

    /**
     * Find the quote of a string segment separated by whitespace that includes a newline.
     */
    private function continuation(int $position): ?int
    {
        $newline = false;

        while ($position < $this->length) {
            $position += strspn($this->sql, " \t\f\v", $position);
            $byte = $this->sql[$position] ?? '';

            if ($byte === "\n" || $byte === "\r") {
                $newline = true;
                ++$position;
            } elseif ($byte === '-' && ($this->sql[$position + 1] ?? '') === '-') {
                $position += strcspn($this->sql, "\r\n", $position);
            } else {
                return $newline && $byte === "'" ? $position : null;
            }
        }

        return null;
    }

    /**
     * Include a UESCAPE clause in a Unicode literal or identifier.
     */
    private function scanUnicodeEscape(): void
    {
        $position = $this->position;
        $this->skipWhitespace();

        if (substr_compare($this->sql, 'UESCAPE', $this->position, 7, true) === 0
            && strspn($this->sql[$this->position + 7] ?? '', self::IDENTIFIER_BYTES) === 0) {
            $this->position += 7;
            $this->skipWhitespace();

            if (($this->sql[$this->position] ?? '') === "'") {
                $this->scanQuoted("'", false, 'string literal');

                return;
            }
        }

        $this->position = $position;
    }

    /**
     * Scan a dollar-quoted string, a positional parameter or a lone dollar sign.
     */
    private function scanDollar(): TokenType
    {
        $start = $this->position;
        $tagEnd = $start + 1;
        $byte = $this->sql[$tagEnd] ?? '';

        if (($byte >= 'a' && $byte <= 'z') || ($byte >= 'A' && $byte <= 'Z') || $byte === '_' || $byte >= "\x80") {
            $tagEnd += 1 + strspn($this->sql, self::DOLLAR_TAG_BYTES, $tagEnd + 1);
        }

        if (($this->sql[$tagEnd] ?? '') !== '$') {
            $this->position = $start + 1 + strspn($this->sql, self::DIGITS, $start + 1);

            return TokenType::Symbol;
        }

        $tag = substr($this->sql, $start, $tagEnd - $start + 1);
        $end = strpos($this->sql, $tag, $tagEnd + 1);

        if ($end === false) {
            throw $this->error('Unterminated dollar-quoted string', $start);
        }

        $this->position = $end + strlen($tag);

        return TokenType::String;
    }

    /**
     * Get the undecoded bodies of a literal's quoted segments and the offset after the last one.
     *
     * @return array{list<string>, int}
     */
    private function segments(int $open, int $end, bool $backslashes): array
    {
        $segments = [];
        $stops = $backslashes ? "'\\" : "'";
        $after = $open;

        while ($open !== null && $open < $end) {
            $position = $open + 1;

            while (true) {
                $position += strcspn($this->sql, $stops, $position);

                if ($this->sql[$position] === '\\') {
                    $position += 2;
                } elseif (($this->sql[$position + 1] ?? '') === "'") {
                    $position += 2;
                } else {
                    break;
                }
            }

            $segments[] = substr($this->sql, $open + 1, $position - $open - 1);
            $after = $position + 1;
            $open = $this->continuation($after);
        }

        return [$segments, $after];
    }

    /**
     * Get the escaped body of a Unicode literal or identifier and its escape character.
     *
     * The lexer only extends these tokens with a UESCAPE clause, whose
     * one-character string ends the token.
     *
     * @return array{string, string}
     */
    private function unicodeParts(int $start, int $end, string $quote): array
    {
        $open = $start + 2;

        if ($quote === '"') {
            $close = $open + 1;

            while (($close = strpos($this->sql, '"', $close)) !== false && ($this->sql[$close + 1] ?? '') === '"') {
                $close += 2;
            }

            $body = substr($this->sql, $open + 1, $close - $open - 1);
            $after = $close + 1;
        } else {
            [$segments, $after] = $this->segments($open, $end, false);
            $body = implode('', $segments);
        }

        return [$body, $after < $end ? $this->sql[$end - 2] : '\\'];
    }

    /**
     * Decode backslash escapes in an E-string segment.
     */
    private function unescape(string $body, int $start): string
    {
        $value = '';
        $length = strlen($body);
        $position = 0;

        while ($position < $length) {
            $run = strcspn($body, "\\'", $position);
            $value .= substr($body, $position, $run);
            $position += $run;

            if ($position >= $length) {
                break;
            }

            if ($body[$position] === "'") {
                $value .= "'";
                $position += 2;
                continue;
            }

            $escaped = $body[$position + 1];
            $position += 2;

            if ($escaped >= '0' && $escaped <= '7') {
                $digits = strspn($body, '01234567', $position - 1, 3);
                $value .= chr(octdec(substr($body, $position - 1, $digits)) & 0xFF);
                $position += $digits - 1;
            } elseif ($escaped === 'x' && ($digits = strspn($body, '0123456789abcdefABCDEF', $position, 2)) > 0) {
                $value .= chr(hexdec(substr($body, $position, $digits)));
                $position += $digits;
            } elseif ($escaped === 'u' || $escaped === 'U') {
                $digits = $escaped === 'u' ? 4 : 8;
                $codePoint = $this->hexadecimal($body, $position, $digits, $start);
                $position += $digits;

                if ($codePoint >= 0xD800 && $codePoint <= 0xDBFF) {
                    $low = substr($body, $position, 2);

                    if ($low !== '\u' && $low !== '\U') {
                        throw $this->error('Invalid Unicode surrogate pair', $start);
                    }

                    $digits = $low === '\u' ? 4 : 8;
                    $codePoint = $this->surrogatePair($codePoint, $this->hexadecimal($body, $position + 2, $digits, $start), $start);
                    $position += 2 + $digits;
                }

                $value .= $this->utf8($codePoint, $start);
            } else {
                $value .= match ($escaped) {
                    'b' => "\x08",
                    'f' => "\f",
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    default => $escaped,
                };
            }
        }

        return $value;
    }

    /**
     * Decode the escapes of a Unicode literal or identifier.
     */
    private function decodeUnicode(string $body, string $escape, int $start): string
    {
        $value = '';
        $length = strlen($body);
        $position = 0;
        $high = null;

        while ($position < $length) {
            $run = strcspn($body, $escape, $position);

            if ($run > 0 && $high !== null) {
                throw $this->error('Invalid Unicode surrogate pair', $start);
            }

            $value .= substr($body, $position, $run);
            $position += $run;

            if ($position >= $length) {
                break;
            }

            if (($body[$position + 1] ?? '') === $escape) {
                $value .= $escape;
                $position += 2;
                continue;
            }

            $wide = ($body[$position + 1] ?? '') === '+';
            $digits = $wide ? 6 : 4;
            $codePoint = $this->hexadecimal($body, $position + ($wide ? 2 : 1), $digits, $start);
            $position += ($wide ? 2 : 1) + $digits;

            if ($high !== null) {
                $codePoint = $this->surrogatePair($high, $codePoint, $start);
                $high = null;
            } elseif ($codePoint >= 0xD800 && $codePoint <= 0xDBFF) {
                $high = $codePoint;
                continue;
            }

            $value .= $this->utf8($codePoint, $start);
        }

        if ($high !== null) {
            throw $this->error('Invalid Unicode surrogate pair', $start);
        }

        return $value;
    }

    /**
     * Read an exact number of hexadecimal digits.
     */
    private function hexadecimal(string $body, int $position, int $digits, int $start): int
    {
        if ($position > strlen($body) || strspn($body, '0123456789abcdefABCDEF', $position, $digits) !== $digits) {
            throw $this->error('Invalid Unicode escape', $start);
        }

        return (int) hexdec(substr($body, $position, $digits));
    }

    /**
     * Combine a UTF-16 surrogate pair.
     */
    private function surrogatePair(int $high, int $low, int $start): int
    {
        if ($low < 0xDC00 || $low > 0xDFFF) {
            throw $this->error('Invalid Unicode surrogate pair', $start);
        }

        return 0x10000 + (($high - 0xD800) << 10) + ($low - 0xDC00);
    }

    /**
     * Encode a Unicode code point as UTF-8.
     */
    private function utf8(int $codePoint, int $start): string
    {
        if (($codePoint >= 0xD800 && $codePoint <= 0xDFFF) || $codePoint > 0x10FFFF || $codePoint === 0) {
            throw $this->error('Invalid Unicode escape value', $start);
        }

        return match (true) {
            $codePoint < 0x80 => chr($codePoint),
            $codePoint < 0x800 => chr(0xC0 | $codePoint >> 6) . chr(0x80 | $codePoint & 0x3F),
            $codePoint < 0x10000 => chr(0xE0 | $codePoint >> 12) . chr(0x80 | $codePoint >> 6 & 0x3F) . chr(0x80 | $codePoint & 0x3F),
            default => chr(0xF0 | $codePoint >> 18) . chr(0x80 | $codePoint >> 12 & 0x3F) . chr(0x80 | $codePoint >> 6 & 0x3F) . chr(0x80 | $codePoint & 0x3F),
        };
    }
}
