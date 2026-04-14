<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\Fluent;

enum BackedEnum: string
{
    case Test = 'test';
    case TestEmpty = '';
}
