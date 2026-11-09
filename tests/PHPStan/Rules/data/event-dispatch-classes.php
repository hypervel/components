<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Data;

use Hypervel\Foundation\Events\Dispatchable;

class HypervelEvent
{
    use Dispatchable;

    /** @var string */
    private $foo;

    /** @var int */
    private $bar;

    public function __construct(string $foo, int $bar)
    {
        $this->foo = $foo;
        $this->bar = $bar;
    }
}

class HypervelEventWithoutConstructor
{
    use Dispatchable;
}
