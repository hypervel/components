<?php

declare(strict_types=1);

use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Foundation\Testing\TestCase;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Testing\PendingCommand;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$basePath = getenv('TESTBENCH_BASE_PATH');
$counter = getenv('PENDING_COMMAND_DD_COUNTER');

if (! is_string($basePath) || $basePath === '' || ! is_string($counter) || $counter === '') {
    fwrite(STDERR, "PendingCommand dd fixture requires its testbench and counter paths.\n");
    exit(2);
}

// The required bootstrap consumes this variable from the inherited file scope.
$APP_BASE_PATH = $basePath;
$app = require $basePath . '/bootstrap/app.php';
$app->make(KernelContract::class)->bootstrap();

Artisan::command('pending-command-dd-fixture', function () use ($counter) {
    $executions = is_file($counter) ? (int) file_get_contents($counter) : 0;
    file_put_contents($counter, (string) ($executions + 1));

    $this->line('fixture output');

    return 7;
});

$test = new class('fixture') extends TestCase {
    /**
     * Provide a concrete test method for the fixture test case.
     */
    public function fixture(): void
    {
    }
};

(new PendingCommand($test, $app, 'pending-command-dd-fixture', []))->dd();
