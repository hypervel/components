<?php

declare(strict_types=1);

namespace Hypervel\Validation\Enums;

/**
 * How size checks interpret a value's "size".
 *
 * Resolved at compile time from sibling type rules in the rule set.
 */
enum SizeMode
{
    case String;
    case Numeric;
    case Array;
    case File;
}
