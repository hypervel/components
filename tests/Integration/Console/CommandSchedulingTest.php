<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Console\CommandSchedulingTest;

use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\Repository;
use Hypervel\Console\Command;
use Hypervel\Console\Scheduling\CacheEventMutex;
use Hypervel\Console\Scheduling\CacheSchedulingMutex;
use Hypervel\Console\Scheduling\EventMutex;
use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Console\Scheduling\SchedulingMutex;
use Hypervel\Container\Container;
use Hypervel\Contracts\Cache\Factory;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Support\Carbon;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class CommandSchedulingTest extends TestCase
{
    use RunTestsInCoroutine;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(now()->startOfYear());

        CommandSchedulingTestCommand::$log = [];

        $cache = new class implements Factory {
            public Repository $store;

            public function __construct()
            {
                $this->store = new Repository(new ArrayStore(true));
            }

            public function store(?string $name = null): Repository
            {
                return $this->store;
            }
        };

        $container = Container::getInstance();

        $container->instance(EventMutex::class, new CacheEventMutex($cache));
        $container->instance(SchedulingMutex::class, new CacheSchedulingMutex($cache));

        Artisan::registerCommand(new CommandSchedulingTestCommand());
    }

    public function testForegroundExecutionOrder()
    {
        $schedule = $this->app->make(Schedule::class);

        $schedule
            ->command('test:scheduling')
            ->onOneServer()
            ->before(function () {
                CommandSchedulingTestCommand::$log[] = 'before';
            })
            ->after(function () {
                CommandSchedulingTestCommand::$log[] = 'after';
            });

        // Run schedule three times to simulate multiple servers.
        // onOneServer() should prevent duplicate execution.
        $this->app->instance(Schedule::class, clone $schedule);
        $this->artisan('schedule:run', ['--once' => true]);

        $this->app->instance(Schedule::class, clone $schedule);
        $this->artisan('schedule:run', ['--once' => true]);

        $this->app->instance(Schedule::class, clone $schedule);
        $this->artisan('schedule:run', ['--once' => true]);

        $this->assertEquals(['before', 'handled', 'after'], CommandSchedulingTestCommand::$log);
    }

    public function testForegroundExecutionOrderWithoutOnOneServer()
    {
        $schedule = $this->app->make(Schedule::class);

        $schedule
            ->command('test:scheduling')
            ->before(function () {
                CommandSchedulingTestCommand::$log[] = 'before';
            })
            ->after(function () {
                CommandSchedulingTestCommand::$log[] = 'after';
            });

        $this->app->instance(Schedule::class, clone $schedule);
        $this->artisan('schedule:run', ['--once' => true]);

        $this->assertEquals(['before', 'handled', 'after'], CommandSchedulingTestCommand::$log);
    }
}

class CommandSchedulingTestCommand extends Command
{
    /** @var string[] */
    public static array $log = [];

    protected ?string $signature = 'test:scheduling';

    protected string $description = 'Test command for scheduling tests.';

    public function handle(): int
    {
        static::$log[] = 'handled';

        return self::SUCCESS;
    }
}
