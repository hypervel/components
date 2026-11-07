<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser\Parsing;

/**
 * Lex mysqldump and mariadb-dump output as the mysql client splits it.
 *
 * Executable comments are SQL, so their markers are read as whitespace and a
 * delimiter inside one still ends the statement. MariaDB-only fragments are
 * read only for MariaDB, as MySQL servers ignore them.
 *
 * @internal
 */
class MySqlLexer extends Lexer
{
    private const string IDENTIFIER_BYTES = self::LETTERS . self::DIGITS . '$' . self::HIGH_BYTES;

    /** Comment starts, and the `*` that closes an executable comment. */
    protected const array COMMENT_STARTS = ['#' => true, '-' => true, '/' => true, '*' => true];

    /**
     * Whitespace and line comments before a token in a run.
     *
     * Repetitions in the native patterns are bounded so one match stays within
     * PCRE's limits; longer input stops the run and is read by the scanner.
     */
    private const string SPACE = '(?:[ \t\n\r\f\x0B]++|(?:#|--(?![^\x00-\x20]))[^\n]*+){0,128}+';

    /**
     * The tokens every run reads: words that are not string prefixes, integers that are not the start of a name or
     * decimal, backquoted names whose closing quote does not begin a doubled one, and punctuation.
     *
     * Comment and executable comment markers, dots and other literals are left to the scanner.
     */
    private const string TOKEN = '[A-Z_$\x80-\xff][A-Z0-9_$\x80-\xff]*+(?![\'"])|\d++(?![A-Z0-9_$.\x80-\xff])|`(?:[^`]++|``){0,128}+`(?!`)|[(),;=]';

    private const string ESCAPED_STRING = '\'(?:[^\'\\\]++|\'\'|\\\[\s\S]){0,128}+\'(?!\')';

    private const string STANDARD_STRING = '\'(?:[^\']++|\'\'){0,128}+\'(?!\')';

    private const string ESCAPED_DOUBLE_STRING = '"(?:[^"\\\]++|""|\\\[\s\S]){0,128}+"(?!")';

    /** A double-quoted string where backslashes are ordinary characters, or an ANSI_QUOTES name; both escape quotes by doubling. */
    private const string STANDARD_DOUBLE_QUOTED = '"(?:[^"]++|""){0,128}+"(?!")';

    /** A run of tokens read in one native call, through the default delimiter or up to a token that needs the scanner. */
    private const string ESCAPED_RUN = '/\G(?<!;)' . self::SPACE . '\K(?:' . self::TOKEN . '|' . self::ESCAPED_STRING . '|' . self::ESCAPED_DOUBLE_STRING . ')/';

    /** A run where backslashes are ordinary characters in strings. */
    private const string STANDARD_RUN = '/\G(?<!;)' . self::SPACE . '\K(?:' . self::TOKEN . '|' . self::STANDARD_STRING . '|' . self::STANDARD_DOUBLE_QUOTED . ')/';

    /** A run where double quotes delimit names and backslashes are escapes in strings. */
    private const string ANSI_RUN = '/\G(?<!;)' . self::SPACE . '\K(?:' . self::TOKEN . '|' . self::ESCAPED_STRING . '|' . self::STANDARD_DOUBLE_QUOTED . ')/';

    /** Whether backslashes in string literals are ordinary characters. */
    public bool $noBackslashEscapes = false;

    /** Whether double quotes delimit identifiers rather than strings. */
    public bool $ansiQuotes = false;

    private string $delimiter = ';';

    /** Whether the delimiter can end an unquoted identifier, such as END$$. */
    private bool $delimiterInWords = false;

    /** The offset of the executable comment currently being read, if any. */
    private ?int $executable = null;

    /** The offset following a dot that directly follows an identifier. */
    private int $qualified = -1;

    private int $identifierEnd = -1;

    /**
     * Create a lexer, reading MariaDB executable comments when requested.
     */
    public function __construct(string $sql, private readonly bool $mariaDb = false)
    {
        parent::__construct($sql);
    }

    /**
     * Append a run of regular tokens, or one token that needs the scanner.
     */
    public function read(array &$types, array &$starts, array &$ends, array &$texts): void
    {
        // Runs end only at the default delimiter, and a name directly after a qualifying dot needs the scanner's state.
        $run = $this->delimiter === ';' && $this->position !== $this->qualified
            ? $this->matchRun($this->noBackslashEscapes ? self::STANDARD_RUN : ($this->ansiQuotes ? self::ANSI_RUN : self::ESCAPED_RUN))
            : null;

        if ($run === null) {
            parent::read($types, $starts, $ends, $texts);

            return;
        }

        $stringType = $this->noBackslashEscapes ? TokenType::String : TokenType::EscapedString;
        $base = $this->windowStart;

        foreach ($run as [$text, $start]) {
            $texts[] = $text;
            $starts[] = $start += $base;

            if ($text[0] === "'") {
                $types[count($texts) - 1] = $stringType;
            } elseif ($text[0] === '"') {
                $types[count($texts) - 1] = $this->ansiQuotes ? TokenType::QuotedIdentifier : $stringType;
            }
        }

        $this->position = $start + strlen($text);
        $type = $types[count($texts) - 1] ?? $this->type($text);

        // A dot qualifies the name that ends directly before it.
        $this->identifierEnd = $type === TokenType::Word || $type === TokenType::QuotedIdentifier ? $this->position : -1;

        if ($text === ';') {
            $index = count($texts) - 1;
            $texts[$index] = '';
            $types[$index] = TokenType::End;
            $this->tokenStart = $start;
            $this->finishStatement();
            $ends[$index] = $this->position;
        }
    }

    /**
     * Get the type of a token appended without one, which follows from its upper-case text.
     */
    public function type(string $text): TokenType
    {
        $byte = $text[0];

        if ($byte === '`') {
            return TokenType::QuotedIdentifier;
        }

        // Digits begin a name unless the whole token is a number.
        if (preg_match('/^(?:\d+\.?\d*|\.\d+)(?:E[-+]?\d+)?$/', $text) === 1) {
            return TokenType::Number;
        }

        return strspn($text, self::IDENTIFIER_BYTES, 0, 1) === 1 ? TokenType::Word : TokenType::Symbol;
    }

    /**
     * Scan the token starting at the current position, or a terminator as End.
     */
    protected function scanToken(): TokenType
    {
        $sql = $this->sql;
        $start = $this->position;
        $byte = $sql[$start];

        if ($byte === $this->delimiter[0] && ($terminator = $this->terminator()) > 0) {
            $this->position += $terminator;

            return TokenType::End;
        }

        if ($byte === "'" || ($byte === '"' && ! $this->ansiQuotes)) {
            $this->scanQuoted($byte, ! $this->noBackslashEscapes, 'string literal');
            $this->identifierEnd = -1;

            return $this->noBackslashEscapes ? TokenType::String : TokenType::EscapedString;
        }

        if ($byte === '`' || $byte === '"') {
            $this->scanQuoted($byte, false, 'quoted identifier');
            $this->identifierEnd = $this->position;

            return TokenType::QuotedIdentifier;
        }

        if ($byte === '.' && $start === $this->identifierEnd) {
            ++$this->position;
            $this->qualified = $this->position;

            return TokenType::Symbol;
        }

        if ($byte >= '0' && $byte <= '9' && $start !== $this->qualified) {
            preg_match('/\G\d+\.?\d*(?:[eE][-+]?\d+)?/', $sql, $matches, 0, $start);
            $number = strlen($matches[0]);
            $after = $sql[$start + $number] ?? '';

            // Digits followed by a name byte begin a name; decimal and exponent literals stay numbers.
            if ($number !== strspn($sql, self::DIGITS, $start)
                || ! (($after >= 'a' && $after <= 'z') || ($after >= 'A' && $after <= 'Z') || $after === '_' || $after === '$' || $after >= "\x80")) {
                return $this->scanNumber($number);
            }
        }

        $identifier = strspn($sql, self::IDENTIFIER_BYTES, $start);

        if ($identifier > 0) {
            return $this->scanWord($identifier);
        }

        $this->identifierEnd = -1;

        if ($byte === '.' && preg_match('/\G\.\d+(?:[eE][-+]?\d+)?/', $sql, $matches, 0, $start) === 1) {
            return $this->scanNumber(strlen($matches[0]));
        }

        ++$this->position;

        return TokenType::Symbol;
    }

    /**
     * Get the length of the delimiter at the current position, or zero.
     */
    protected function terminator(): int
    {
        $length = strlen($this->delimiter);

        return substr_compare($this->sql, $this->delimiter, $this->position, $length) === 0 ? $length : 0;
    }

    /**
     * Apply a mysql client DELIMITER command between statements.
     */
    protected function skipCommand(): bool
    {
        if (preg_match('/\GDELIMITER[\t ]+(\S+)[\t ]*(?:\r?\n|$)/i', $this->sql, $matches, 0, $this->position) !== 1) {
            return false;
        }

        $this->delimiter = $matches[1];
        $this->delimiterInWords = strpbrk($this->delimiter, self::IDENTIFIER_BYTES) !== false;
        $this->position += strlen($matches[0]);

        return true;
    }

    /**
     * Reset the qualification state at a statement boundary.
     */
    protected function startStatement(): void
    {
        $this->qualified = -1;
        $this->identifierEnd = -1;
    }

    /**
     * Skip whitespace and comments, failing at an unterminated executable comment.
     */
    protected function skipWhitespace(): void
    {
        parent::skipWhitespace();

        if ($this->executable !== null && $this->position >= $this->length) {
            throw $this->error('Unterminated executable comment', $this->executable);
        }
    }

    /**
     * Skip comments and executable comment markers.
     */
    protected function skipComment(): bool
    {
        $sql = $this->sql;
        $position = $this->position;
        $byte = $sql[$position];
        $next = $sql[$position + 1] ?? '';

        if ($byte === '#' || ($byte === '-' && $next === '-' && ($sql[$position + 2] ?? ' ') <= ' ')) {
            $this->skipLine();

            return true;
        }

        if ($byte === '*' && $next === '/' && $this->executable !== null) {
            $this->executable = null;
            $this->position += 2;

            return true;
        }

        if ($byte !== '/' || $next !== '*') {
            return false;
        }

        $marker = $sql[$position + 2] ?? '';

        if ($this->executable === null) {
            if ($marker === '!') {
                $this->executable = $position;
                $this->position += 3 + strspn($sql, self::DIGITS, $position + 3);

                return true;
            }

            // The sandbox directive is a mariadb client command, not SQL.
            if ($this->mariaDb && $marker === 'M' && ($sql[$position + 3] ?? '') === '!') {
                $digits = strspn($sql, self::DIGITS, $position + 4);

                if (substr_compare($sql, '\-', $position + 4 + $digits, 2) !== 0) {
                    $this->executable = $position;
                    $this->position += 4 + $digits;

                    return true;
                }
            }
        }

        $end = strpos($sql, '*/', $position + 2);

        if ($end === false) {
            throw $this->error('Unterminated comment', $position);
        }

        $this->position = $end + 2;

        return true;
    }

    /**
     * Decode a string literal, including backslash escapes and prefixes.
     */
    protected function string(TokenType $type, int $start, int $end): string
    {
        if ($type === TokenType::String) {
            return parent::string($type, $start, $end);
        }

        $open = $start + strcspn($this->sql, "'\"", $start);
        $quote = $this->sql[$open];
        $body = substr($this->sql, $open + 1, $end - $open - 2);
        $value = '';
        $length = strlen($body);
        $position = 0;

        while ($position < $length) {
            $run = strcspn($body, '\\' . $quote, $position);
            $value .= substr($body, $position, $run);
            $position += $run;

            if ($position >= $length) {
                break;
            }

            if ($body[$position] === $quote) {
                $value .= $quote;
                $position += 2;
                continue;
            }

            $escaped = $body[$position + 1];
            $position += 2;
            $value .= match ($escaped) {
                '0' => "\0",
                'b' => "\x08",
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                'Z' => "\x1a",
                // Outside pattern matching, MySQL retains these backslashes.
                '%', '_' => '\\' . $escaped,
                default => $escaped,
            };
        }

        return $value;
    }

    /**
     * Scan an identifier, keyword or literal prefix.
     */
    private function scanWord(int $length): TokenType
    {
        $start = $this->position;

        if ($this->delimiterInWords) {
            $first = $this->delimiter[0];
            $delimiterLength = strlen($this->delimiter);

            for ($offset = strcspn($this->sql, $first, $start, $length); $offset < $length; $offset += 1 + strcspn($this->sql, $first, $start + $offset + 1, $length - $offset - 1)) {
                if (substr_compare($this->sql, $this->delimiter, $start + $offset, $delimiterLength) === 0) {
                    $length = $offset;
                    break;
                }
            }
        }

        $this->position += $length;
        $next = $this->sql[$this->position] ?? '';

        if ($next === "'" || ($next === '"' && ! $this->ansiQuotes)) {
            $prefix = $this->sql[$start];

            if ($length === 1 && $next === "'" && ($prefix === 'x' || $prefix === 'X' || $prefix === 'b' || $prefix === 'B')) {
                $this->scanQuoted($next, false, 'string literal');
                $this->identifierEnd = -1;

                return TokenType::String;
            }

            if (($length === 1 && ($prefix === 'n' || $prefix === 'N')) || $prefix === '_') {
                $this->scanQuoted($next, ! $this->noBackslashEscapes, 'string literal');
                $this->identifierEnd = -1;

                return $this->noBackslashEscapes ? TokenType::String : TokenType::EscapedString;
            }
        }

        $this->identifierEnd = $this->position;

        return TokenType::Word;
    }

    /**
     * Consume a numeric literal of the given length.
     */
    private function scanNumber(int $length): TokenType
    {
        $this->position += $length;
        $this->identifierEnd = -1;

        return TokenType::Number;
    }
}
