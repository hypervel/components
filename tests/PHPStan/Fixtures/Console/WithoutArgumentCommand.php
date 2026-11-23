<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Console;

class WithoutArgumentCommand extends LookupCommand
{
    protected ?string $signature = 'lookup:without';
}
