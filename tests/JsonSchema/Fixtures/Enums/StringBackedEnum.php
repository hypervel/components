<?php

declare(strict_types=1);

namespace Hypervel\Tests\JsonSchema\Fixtures\Enums;

enum StringBackedEnum: string
{
    case One = 'one';
    case Two = 'two';
}
