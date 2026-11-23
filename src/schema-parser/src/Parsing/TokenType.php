<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser\Parsing;

/**
 * @internal
 */
enum TokenType
{
    case Word;
    case QuotedIdentifier;

    /** A string literal whose quotes are escaped only by doubling. */
    case String;

    /** A string literal in which backslashes also escape characters. */
    case EscapedString;

    case Number;
    case Symbol;

    /** The statement terminator, or the end of the input. */
    case End;
}
