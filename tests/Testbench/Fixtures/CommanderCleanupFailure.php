<?php

declare(strict_types=1);

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Application;
use Hypervel\Testbench\Bootstrapper;
use Hypervel\Testbench\Console\Commander;
use Hypervel\Testbench\Foundation\Config;
use Hypervel\Testbench\Foundation\Console\TerminatingConsole;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

if (getenv('COMMANDER_FIXTURE_MODE') === 'signal') {
    $commander = new class([], dirname(__DIR__, 3)) extends Commander {
        public function useApplication(ApplicationContract $app): void
        {
            $this->app = $app;
        }

        public function prepareSignals(): void
        {
            $this->prepareCommandSignals();
        }
    };

    TerminatingConsole::before(static fn (): never => throw new RuntimeException('Signal cleanup failed.'));

    if (getenv('COMMANDER_FIXTURE_SIGNAL') === 'SIGINT') {
        $commander->useApplication(new Application(dirname(__DIR__, 3)));
    }

    $commander->prepareSignals();
    posix_kill(
        getmypid(),
        getenv('COMMANDER_FIXTURE_SIGNAL') === 'SIGINT' ? SIGINT : SIGTERM,
    );

    exit(255);
}

define('TESTBENCH_CORE', true);

Bootstrapper::bootstrap();

$config = Bootstrapper::getConfiguration() ?? new Config;
$workingPath = getenv('TESTBENCH_WORKING_PATH');

TerminatingConsole::before(static fn (): never => throw new RuntimeException('Command cleanup failed.'));

(new Commander($config, is_string($workingPath) ? $workingPath : getcwd()))->handle();
