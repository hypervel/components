<?php

declare(strict_types=1);

namespace Tests;

use Hypervel\Contracts\Console\Kernel;
use Hypervel\Contracts\Foundation\Application;

trait CreatesApplication
{
    /**
     * Create a new application instance.
     */
    public function createApplication(): Application
    {
        $app = require __DIR__ . '/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
