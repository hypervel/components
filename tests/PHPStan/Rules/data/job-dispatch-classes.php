<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Data;

use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Foundation\Bus\PendingDispatch;

class HypervelJob
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

class HypervelJobWithoutConstructor
{
    use Dispatchable;
}

class HypervelJobWithPrivateConstructor
{
    use Dispatchable;

    private function __construct(string $foo)
    {
    }
}

class HypervelJobWithOwnDispatch
{
    use Dispatchable;

    public function __construct(public string $id)
    {
    }

    public static function dispatch(int $id): PendingDispatch
    {
        return static::newPendingDispatch(new static((string) $id));
    }
}

class HypervelJobWithProtectedDispatch
{
    use Dispatchable {
        dispatch as protected;
    }

    public function __construct(public string $id)
    {
    }
}

class HypervelJobWithReferenceDispatch
{
    use Dispatchable;

    public function __construct(public int $id)
    {
    }

    public static function dispatch(int &$id): PendingDispatch
    {
        return static::newPendingDispatch(new static($id));
    }
}
