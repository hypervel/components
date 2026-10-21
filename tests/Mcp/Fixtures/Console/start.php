<?php

declare(strict_types=1);

use Hypervel\Contracts\Console\Kernel;
use Hypervel\Mcp\Server\McpServiceProvider;
use Hypervel\Testbench\Bootstrapper;
use Hypervel\Testbench\Foundation\Application;
use Hypervel\Tests\Mcp\Fixtures\Workbench\Providers\WorkbenchServiceProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

Bootstrapper::bootstrap();

$app = Application::create(options: [
    'extra' => ['providers' => [McpServiceProvider::class, WorkbenchServiceProvider::class]],
]);
$kernel = $app->make(Kernel::class);
$input = new ArrayInput(['command' => 'mcp:start', 'handle' => 'test-mcp']);
$status = $kernel->handle($input, new ConsoleOutput);
$kernel->terminate($input, $status);

exit($status);
