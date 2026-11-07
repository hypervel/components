<?php

declare(strict_types=1);

namespace Hypervel\Tests\SchemaParser\Fixtures;

use Hypervel\SchemaParser\Parsing\Lexer;

trait LexesStatements
{
    /**
     * Read every statement, returning its offset and its source text through its last token.
     *
     * @return list<array{int, string}>
     */
    private static function statements(Lexer $lexer): array
    {
        $statements = [];

        while (($tokens = $lexer->nextStatement()) !== null) {
            while (! $tokens->atEnd()) {
                $tokens->next();
            }

            $statements[] = [$tokens->start, $tokens->spanFrom($tokens->start)];
        }

        return $statements;
    }

    /**
     * Get the source text of each token in the first statement.
     *
     * @return list<string>
     */
    private static function tokens(Lexer $lexer): array
    {
        $tokens = $lexer->nextStatement();
        $texts = [];

        while ($tokens !== null && ! $tokens->atEnd()) {
            $start = $tokens->offset();
            $tokens->next();
            $texts[] = $tokens->spanFrom($start);
        }

        return $texts;
    }

    /**
     * Skip the first group of the first statement, returning its source text and the text of the token after it.
     *
     * @return array{string, string}
     */
    private static function group(Lexer $lexer): array
    {
        $tokens = $lexer->nextStatement();

        while (! $tokens->isSymbol('(')) {
            $tokens->next();
        }

        $start = $tokens->offset();
        $tokens->skipGroup();

        return [$tokens->spanFrom($start), $tokens->text()];
    }

    /**
     * Get each statement's offset and terminator span, after reading or skipping its tokens.
     *
     * @return list<array{int, int, int}>
     */
    private static function boundaries(Lexer $lexer, bool $skip): array
    {
        $boundaries = [];

        while (($tokens = $lexer->nextStatement()) !== null) {
            if ($skip) {
                $tokens->skipRest();
            }

            while (! $tokens->atEnd()) {
                $tokens->next();
            }

            $boundaries[] = [$tokens->start, $tokens->offset(), $tokens->offset() + strlen($tokens->value())];
        }

        return $boundaries;
    }
}
