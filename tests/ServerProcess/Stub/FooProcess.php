<?php

declare(strict_types=1);

namespace Hypervel\Tests\ServerProcess\Stub;

use Hypervel\ServerProcess\AbstractProcess;

class FooProcess extends AbstractProcess
{
    public bool $enableCoroutine = false;

    public int $restartInterval = 0;

    public static bool $handled = false;

    public function handle(): void
    {
        static::$handled = true;
    }
}
