<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Fixtures;

use Hypervel\Foundation\Application;

class BootstrapFileApplication extends Application
{
    public string $bootstrapFile = '';

    public int $frameworkBootstrapCount = 0;
}
