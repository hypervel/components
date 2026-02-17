<?php

declare(strict_types=1);

namespace Hypervel\Tests\Server\Stub;

use Hyperf\Process\AbstractProcess;

class DemoProcess extends AbstractProcess
{
    public string $name = 'test.demo';

    public function handle(): void
    {
    }
}
