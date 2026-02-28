<?php

declare(strict_types=1);

namespace Hypervel\Tests\Server\Stub;

use Hypervel\ServerProcess\AbstractProcess;

class DemoProcess extends AbstractProcess
{
    public string $name = 'test.demo';

    public function handle(): void
    {
    }
}
