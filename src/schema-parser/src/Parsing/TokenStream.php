<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser\Parsing;

use Hypervel\SchemaParser\ParseException;

/**
 * The tokens of one statement, read in runs and never past its terminator.
 *
 * A token is its source offset and upper-case source text; its type is derived
 * from the text when first needed unless the lexer recorded it. Tokens that were
 * read are kept, so a parser can look behind the current one or rewind. Source
 * skipped by skipGroup() or skipRest() is never read, so it cannot be replayed.
 *
 * @template-covariant TLexer of Lexer
 *
 * @internal
 */
final class TokenStream
{
    /** @var array<int, TokenType> types recorded by the lexer or derived so far */
    private array $types = [];

    /** @var list<int> */
    private array $starts = [];

    /** @var array<int, int> the ends of terminators, whose spans can include COPY data */
    private array $ends = [];

    /** @var list<string> upper-case source texts, or an empty string for a terminator */
    private array $texts = [];

    private int $index = 0;

    /**
     * Begin reading a statement at its first token.
     *
     * @param TLexer $lexer
     */
    public function __construct(
        public readonly Lexer $lexer,
        public readonly int $start,
    ) {
        $lexer->read($this->types, $this->starts, $this->ends, $this->texts);
    }

    /**
     * Consume the current token.
     */
    public function next(): void
    {
        if ($this->texts[$this->index] !== '') {
            $this->advance();
        }
    }

    /**
     * Get the type of the current token or one after it.
     *
     * @phpstan-impure
     */
    public function type(int $ahead = 0): TokenType
    {
        $index = $ahead === 0 ? $this->index : $this->at($ahead);

        return $this->types[$index] ??= $this->lexer->type($this->texts[$index]);
    }

    /**
     * Get the source offset of the current token or one after it.
     *
     * @phpstan-impure
     */
    public function offset(int $ahead = 0): int
    {
        return $this->starts[$ahead === 0 ? $this->index : $this->at($ahead)];
    }

    /**
     * Get the upper-case keyword of the current token or one after it, or null when it is not a word.
     *
     * @phpstan-impure
     */
    public function keyword(int $ahead = 0): ?string
    {
        $index = $ahead === 0 ? $this->index : $this->at($ahead);

        return ($this->types[$index] ??= $this->lexer->type($this->texts[$index])) === TokenType::Word ? $this->texts[$index] : null;
    }

    /**
     * Get the upper-case source text of the current token or one after it, or an empty string for the terminator.
     *
     * @phpstan-impure
     */
    public function text(int $ahead = 0): string
    {
        return $this->texts[$ahead === 0 ? $this->index : $this->at($ahead)];
    }

    /**
     * Determine whether the next tokens are the given sequence of keywords, without consuming them.
     *
     * @phpstan-impure
     */
    public function is(string $keyword, string ...$following): bool
    {
        return $this->texts[$this->index] === $keyword && ($following === [] || $this->follows($following));
    }

    /**
     * Determine whether the current token, or one before or after it, is the given punctuation.
     *
     * @phpstan-impure
     */
    public function isSymbol(string $symbol, int $ahead = 0): bool
    {
        return $this->texts[$ahead === 0 ? $this->index : $this->at($ahead)] === $symbol;
    }

    /**
     * Consume a sequence of keywords when all of them are next.
     *
     * @phpstan-impure
     */
    public function accept(string $keyword, string ...$following): bool
    {
        if ($this->texts[$this->index] !== $keyword || ($following !== [] && ! $this->follows($following))) {
            return false;
        }

        $this->index += count($following);
        $this->advance();

        return true;
    }

    /**
     * Consume the current token and get its decoded value when it is a word or quoted identifier.
     *
     * @phpstan-impure
     */
    public function acceptName(): ?string
    {
        $index = $this->index;
        $type = $this->types[$index] ??= $this->lexer->type($this->texts[$index]);

        if ($type !== TokenType::Word && $type !== TokenType::QuotedIdentifier) {
            return null;
        }

        $name = $this->lexer->value($type, $this->starts[$index], $this->end($index));
        $this->advance();

        return $name;
    }

    /**
     * Consume the given punctuation when it is next.
     *
     * @phpstan-impure
     */
    public function acceptSymbol(string $symbol): bool
    {
        if ($this->texts[$this->index] !== $symbol) {
            return false;
        }

        $this->advance();

        return true;
    }

    /**
     * Consume required punctuation.
     */
    public function expectSymbol(string $symbol, string $description): void
    {
        if ($this->texts[$this->index] !== $symbol) {
            throw $this->error($description);
        }

        $this->advance();
    }

    /**
     * Consume a group from its opening parenthesis, the current token, through the matching parenthesis.
     */
    public function skipGroup(): void
    {
        $start = $this->starts[$this->index];
        $depth = 0;

        do {
            $text = $this->texts[$this->index];

            if ($text === '(') {
                ++$depth;
            } elseif ($text === ')') {
                --$depth;
            } elseif ($text === '') {
                throw $this->lexer->error('Unterminated parenthesized expression', $start);
            }

            // The rest of the group is not read as tokens: its source is skipped and its closing parenthesis appended.
            if ($depth > 0 && ! isset($this->texts[$this->index + 1])) {
                $this->starts[] = $this->lexer->skipGroup($depth)
                    ?? throw $this->lexer->error('Unterminated parenthesized expression', $start);
                $this->texts[] = ')';
                ++$this->index;
                $depth = 0;
            }

            $this->advance();
        } while ($depth > 0);
    }

    /**
     * Consume an expression that ends before a top-level comma, an unmatched closing bracket or one of the given keywords.
     *
     * Brackets and CASE ... END nest. The first token never ends it, so DEFAULT
     * NULL keeps its NULL, and words beside a qualification dot are names.
     * Returns whether anything was consumed.
     *
     * @param array<string, true> $keywords
     *
     * @phpstan-impure
     */
    public function skipExpression(array $keywords): bool
    {
        $start = $this->index;
        $depth = 0;
        $cases = 0;

        // Quoted tokens keep their delimiters, so only a word can equal a keyword and comparisons need no type checks.
        while (($text = $this->texts[$this->index]) !== '') {
            if ($text === '(' || $text === '[') {
                ++$depth;
            } elseif ($text === ')' || $text === ']') {
                if ($depth-- === 0) {
                    break;
                }
            } elseif ($depth === 0) {
                if ($text === ',') {
                    break;
                }

                if ($text === 'CASE') {
                    ++$cases;
                } elseif ($text === 'END' && $cases > 0) {
                    --$cases;
                } elseif ($cases === 0 && $this->index > $start && isset($keywords[$text])
                    && $this->texts[$this->index - 1] !== '.' && $this->texts[$this->at(1)] !== '.') {
                    break;
                }
            }

            $this->advance();
        }

        return $this->index > $start;
    }

    /**
     * Get a mark of the current position to return to.
     */
    public function mark(): int
    {
        return $this->index;
    }

    /**
     * Return to a marked position within the statement.
     *
     * A group consumed by skipGroup() keeps only its brackets as tokens, so a mark cannot be reused across one.
     */
    public function rewind(int $mark): void
    {
        $this->index = $mark;
    }

    /**
     * Determine whether the statement has no more tokens.
     */
    public function atEnd(): bool
    {
        return $this->texts[$this->index] === '';
    }

    /**
     * Get the decoded value of the current token or one after it.
     *
     * @phpstan-impure
     */
    public function value(int $ahead = 0): string
    {
        $index = $ahead === 0 ? $this->index : $this->at($ahead);

        return $this->lexer->value($this->types[$index] ??= $this->lexer->type($this->texts[$index]), $this->starts[$index], $this->end($index));
    }

    /**
     * Get the source text from an offset through the most recently consumed token.
     *
     * @phpstan-impure
     */
    public function spanFrom(int $start): string
    {
        $end = $this->index === 0 ? $this->start : $this->end($this->index - 1);

        return substr($this->lexer->sql, $start, $end - $start);
    }

    /**
     * Skip the unread remainder of the statement without reading its tokens.
     */
    public function skipRest(): void
    {
        $last = count($this->texts) - 1;

        if ($this->texts[$last] !== '') {
            ++$last;
            [$this->starts[], $this->ends[$last]] = $this->lexer->skipStatement();
            $this->types[$last] = TokenType::End;
            $this->texts[] = '';
        }

        $this->index = $last;
    }

    /**
     * Create a parse error located at the current token or one after it.
     */
    public function error(string $message, int $ahead = 0): ParseException
    {
        return $this->lexer->error($message, $this->offset($ahead));
    }

    /**
     * Move past a token known not to be the terminator, reading the next run of tokens when needed.
     */
    private function advance(): void
    {
        if (! isset($this->texts[++$this->index])) {
            $this->lexer->read($this->types, $this->starts, $this->ends, $this->texts);
        }
    }

    /**
     * Get the end offset of a token.
     */
    private function end(int $index): int
    {
        return $this->ends[$index] ?? $this->starts[$index] + strlen($this->texts[$index]);
    }

    /**
     * Get the index of a token relative to the current one, reading more when needed but never past the terminator.
     */
    private function at(int $ahead): int
    {
        $index = $this->index + $ahead;

        if (isset($this->texts[$index])) {
            return $index;
        }

        while ($index >= count($this->texts)) {
            if ($this->texts[count($this->texts) - 1] === '') {
                return count($this->texts) - 1;
            }

            $this->lexer->read($this->types, $this->starts, $this->ends, $this->texts);
        }

        return $index;
    }

    /**
     * Determine whether the tokens after the current one are the given keywords.
     *
     * @param non-empty-list<string> $keywords
     */
    private function follows(array $keywords): bool
    {
        foreach ($keywords as $position => $keyword) {
            if ($this->texts[$this->at($position + 1)] !== $keyword) {
                return false;
            }
        }

        return true;
    }
}
