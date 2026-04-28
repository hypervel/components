<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support\Fixtures;

enum StringBackedEnum: string
{
    case AdminLabel = 'I am \'admin\'';
    case HelloWorld = 'Hello world';
}
