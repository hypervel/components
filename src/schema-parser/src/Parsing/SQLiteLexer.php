<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser\Parsing;

/**
 * Lex SQLite schema output with the statement boundaries of sqlite3_complete().
 *
 * @internal
 */
class SQLiteLexer extends Lexer
{
    protected const string WHITESPACE = " \t\n\f\r";

    protected const array COMMENT_STARTS = ['-' => true, '/' => true, "\xEF" => true];

    private const string IDENTIFIER_BYTES = self::LETTERS . self::DIGITS . '$' . self::HIGH_BYTES;

    /**
     * Whitespace, line comments and byte order marks before a token in a run.
     *
     * Repetitions in the native patterns are bounded so one match stays within
     * PCRE's limits; longer input stops the run and is read by the scanner.
     */
    private const string SPACE = '(?:[ \t\n\f\r]++|--[^\n]*+|\xEF\xBB\xBF){0,128}+';

    /**
     * The tokens a run reads, as the scanner splits them: words that do not run into a string, numbers, quoted
     * names whose closing quote does not begin a doubled one, strings, and any other byte as a symbol.
     *
     * Block comments, signs and blob literals are left to the scanner.
     */
    private const string TOKEN = '[A-Z_\x80-\xff][A-Z0-9_$\x80-\xff]*+(?!\')'
        . '|0X[0-9A-F_]++|(?:\d[\d_]*+(?:\.[\d_]*+)?|\.\d[\d_]*+)(?:E[+-]?\d++)?'
        . '|"(?:[^"]++|""){0,256}+"(?!")|`(?:[^`]++|``){0,256}+`(?!`)|\[[^\]]*+\]'
        . '|\'(?:[^\']++|\'\'){0,256}+\'(?!\')'
        . '|[^ \t\n\f\r\'"`\[A-Z0-9_\x80-\xff\/-]';

    /** A run of tokens read in one native call, through a semicolon or up to a token that needs the scanner. */
    private const string RUN = '/\G(?<!;)' . self::SPACE . '\K(?:' . self::TOKEN . ')/';

    /** A statement that cannot be a trigger. */
    private const int NORMAL = 0;

    /** The CREATE [TEMP] TRIGGER prefix is still possible. */
    private const int CREATE = 1;

    /** A trigger body, where semicolons do not end the statement. */
    private const int TRIGGER = 2;

    /** A semicolon inside a trigger body. */
    private const int TRIGGER_SEMICOLON = 3;

    /** END directly after a semicolon inside a trigger body. */
    private const int TRIGGER_END = 4;

    private int $state = self::NORMAL;

    private bool $started = false;

    /**
     * Append a run of regular tokens, or one token that needs the scanner.
     */
    public function read(array &$types, array &$starts, array &$ends, array &$texts): void
    {
        // Until a statement is known not to be a trigger, its words and semicolons need the scanner's completion state.
        $run = $this->started && $this->state === self::NORMAL ? $this->matchRun(self::RUN) : null;

        if ($run === null) {
            parent::read($types, $starts, $ends, $texts);

            return;
        }

        $base = $this->windowStart;

        foreach ($run as [$text, $start]) {
            $texts[] = $text;
            $starts[] = $start += $base;

            if ($text[0] === "'") {
                $types[count($texts) - 1] = TokenType::String;
            }
        }

        $this->position = $start + strlen($text);

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

        if (($byte >= 'A' && $byte <= 'Z') || $byte === '_' || $byte >= "\x80") {
            return TokenType::Word;
        }

        if (($byte >= '0' && $byte <= '9') || ($byte === '.' && isset($text[1]))) {
            return TokenType::Number;
        }

        return $byte === '"' || $byte === '`' || $byte === '[' ? TokenType::QuotedIdentifier : TokenType::Symbol;
    }

    /**
     * Scan the token starting at the current position, or a terminator as End.
     */
    protected function scanToken(): TokenType
    {
        $sql = $this->sql;
        $byte = $sql[$this->position];

        if (($byte >= 'a' && $byte <= 'z') || ($byte >= 'A' && $byte <= 'Z') || $byte === '_' || $byte >= "\x80") {
            $start = $this->position;
            $this->position += 1 + strspn($sql, self::IDENTIFIER_BYTES, $start + 1);

            if ($this->position === $start + 1 && ($byte === 'x' || $byte === 'X') && ($sql[$this->position] ?? '') === "'") {
                $this->scanQuoted("'", false, 'blob literal');
                $this->advance(false);

                return TokenType::String;
            }

            $this->advanceWord($start);

            return TokenType::Word;
        }

        if ($byte === ';') {
            ++$this->position;

            if ($this->state === self::TRIGGER || $this->state === self::TRIGGER_SEMICOLON) {
                $this->state = self::TRIGGER_SEMICOLON;

                return TokenType::Symbol;
            }

            return TokenType::End;
        }

        if ($byte === "'") {
            $this->scanQuoted("'", false, 'string literal');
            $this->advance(false);

            return TokenType::String;
        }

        if ($byte === '"' || $byte === '`') {
            $this->scanQuoted($byte, false, 'quoted identifier');
            $this->advance(false);

            return TokenType::QuotedIdentifier;
        }

        if ($byte === '[') {
            $end = strpos($sql, ']', $this->position);

            if ($end === false) {
                throw $this->error('Unterminated quoted identifier', $this->position);
            }

            $this->position = $end + 1;
            $this->advance(false);

            return TokenType::QuotedIdentifier;
        }

        $this->advance(false);

        if (($byte >= '0' && $byte <= '9') || ($byte === '.' && ($sql[$this->position + 1] ?? '') >= '0' && ($sql[$this->position + 1] ?? '') <= '9')) {
            preg_match('/\G(?:0[xX][0-9A-Fa-f_]+|(?:\d[\d_]*(?:\.[\d_]*)?|\.\d[\d_]*)(?:[eE][+-]?\d+)?)/', $sql, $matches, 0, $this->position);
            $this->position += strlen($matches[0]);

            return TokenType::Number;
        }

        ++$this->position;

        return TokenType::Symbol;
    }

    /**
     * Skip a comment, or a UTF-8 byte order mark, which SQLite reads as whitespace before any token.
     */
    protected function skipComment(): bool
    {
        if ($this->sql[$this->position] === "\xEF" && substr_compare($this->sql, "\xEF\xBB\xBF", $this->position, 3) === 0) {
            $this->position += 3;

            return true;
        }

        return parent::skipComment();
    }

    /**
     * Reset the trigger completion state.
     */
    protected function startStatement(): void
    {
        $this->state = self::NORMAL;
        $this->started = false;
    }

    /**
     * Advance the trigger state over a word, as sqlite3_complete() classifies it.
     */
    private function advanceWord(int $start): void
    {
        $length = $this->position - $start;

        if ($this->started && $this->state !== self::CREATE) {
            if ($this->state !== self::NORMAL) {
                $this->advance($length === 3 && substr_compare($this->sql, 'END', $start, 3, true) === 0);
            }

            return;
        }

        $word = strtoupper(substr($this->sql, $start, $length));

        if (! $this->started) {
            $this->started = true;
            $this->state = $word === 'CREATE' ? self::CREATE : self::NORMAL;
        } elseif ($word !== 'TEMP' && $word !== 'TEMPORARY') {
            $this->state = $word === 'TRIGGER' ? self::TRIGGER : self::NORMAL;
        }
    }

    /**
     * Advance the trigger state over a token other than a semicolon.
     */
    private function advance(bool $end): void
    {
        $this->started = true;
        $this->state = match ($this->state) {
            self::TRIGGER, self::TRIGGER_END => self::TRIGGER,
            self::TRIGGER_SEMICOLON => $end ? self::TRIGGER_END : self::TRIGGER,
            default => self::NORMAL,
        };
    }
}
