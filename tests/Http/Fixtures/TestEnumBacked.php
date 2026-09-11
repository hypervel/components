<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Fixtures;

enum TestEnumBacked: string
{
    case test = 'test';
    case test_empty = '';
}
