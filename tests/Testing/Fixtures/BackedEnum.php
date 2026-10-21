<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\Fixtures;

enum BackedEnum: string
{
    case Test = 'test';
    case TestEmpty = '';
}
