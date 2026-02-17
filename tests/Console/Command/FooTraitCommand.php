<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Command;

use Hypervel\Console\Command;

class FooTraitCommand extends Command
{
    use Traits\Foo;

    public function __construct(?string $name = null)
    {
        parent::__construct($name);
    }

    public function handle(): void
    {
    }
}
