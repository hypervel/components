<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console;

use Hypervel\Console\Command;
use Hypervel\Console\Commands\ScheduleClearCacheCommand;
use Hypervel\Console\Commands\ScheduleInterruptCommand;
use Hypervel\Console\Commands\ScheduleListCommand;
use Hypervel\Console\Commands\SchedulePauseCommand;
use Hypervel\Console\Commands\ScheduleResumeCommand;
use Hypervel\Console\Commands\ScheduleRunCommand;
use Hypervel\Console\Commands\ScheduleTestCommand;
use Hypervel\Console\ConsoleServiceProvider;
use Hypervel\Console\Events\BeforeHandle;
use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Channel;
use Hypervel\Log\Context\Events\ContextHydrated;
use Hypervel\Log\Context\Repository;
use Hypervel\Support\Env;
use Hypervel\Support\Facades\Context;
use Hypervel\Testbench\Attributes\WithEnv;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Process\Process;

class ConsoleServiceProviderTest extends TestCase
{
    public function testScheduleCommandsAreRegistered()
    {
        $kernel = $this->app->make(KernelContract::class);
        $artisan = $kernel->getArtisan();

        $expectedCommands = [
            'schedule:clear-cache' => ScheduleClearCacheCommand::class,
            'schedule:list' => ScheduleListCommand::class,
            'schedule:run' => ScheduleRunCommand::class,
            'schedule:interrupt' => ScheduleInterruptCommand::class,
            'schedule:pause' => SchedulePauseCommand::class,
            'schedule:resume' => ScheduleResumeCommand::class,
            'schedule:continue' => ScheduleResumeCommand::class,
            'schedule:test' => ScheduleTestCommand::class,
        ];

        foreach ($expectedCommands as $name => $class) {
            $this->assertTrue($artisan->has($name), "Command '{$name}' should be registered");
            $this->assertInstanceOf($class, $artisan->find($name));
        }
    }

    #[DataProvider('putenvAdapters')]
    public function testProcessContextIsHydratedOnlyForTheInitialCommand(bool $putenvEnabled): void
    {
        $payload = [
            'data' => ['task' => serialize('concurrency')],
            'hidden' => ['token' => serialize('secret')],
        ];
        $restoreEnvironment = (new WithEnv('__HYPERVEL_CONTEXT', base64_encode(serialize($payload))))($this->app);

        try {
            if (! $putenvEnabled) {
                Env::disablePutenv();
            }

            $events = $this->app->make('events');
            (new ConsoleServiceProvider($this->app))->boot($events);

            $this->assertNull(Env::get('__HYPERVEL_CONTEXT'));
            $this->assertFalse(getenv('__HYPERVEL_CONTEXT'));
            $this->assertArrayNotHasKey('__HYPERVEL_CONTEXT', $_ENV);
            $this->assertArrayNotHasKey('__HYPERVEL_CONTEXT', $_SERVER);

            $process = new Process([PHP_BINARY, '-r', 'echo json_encode(getenv("__HYPERVEL_CONTEXT"));']);
            $process->mustRun();
            $this->assertSame('false', $process->getOutput());

            $command = new BeforeHandle(new Command('context:test'), new ArrayInput([]));
            $hydrations = 0;
            $received = null;

            Context::hydrated(static function (Repository $context) use ($events, $command, &$hydrations, &$received): void {
                ++$hydrations;
                $received = [$context->get('task'), $context->getHidden('token')];
                $context->add('task', 'updated');

                if ($hydrations === 1) {
                    $events->dispatch($command);
                }
            });

            $events->dispatch($command);

            $this->assertSame(['concurrency', 'secret'], $received);
            $this->assertSame(1, $hydrations);
            $this->assertSame('updated', Context::get('task'));

            Context::add('task', 'later');
            $events->dispatch($command);

            $this->assertSame('later', Context::get('task'));

            $result = new Channel(1);
            Coroutine::create(static function () use ($events, $command, $result): void {
                $events->dispatch($command);
                $result->push([Repository::hasInstance()]);
            });

            $this->assertSame([false], $result->pop(1));
            $this->assertSame(1, $hydrations);
        } finally {
            Env::enablePutenv();
            $restoreEnvironment();
        }
    }

    /**
     * Provide environment adapter configurations.
     */
    public static function putenvAdapters(): array
    {
        return [
            'putenv enabled' => [true],
            'putenv disabled' => [false],
        ];
    }

    #[DataProvider('contextsWithoutProcessHydration')]
    public function testProcessContextIsNotHydratedWithoutAConsolePayload(bool $runningInConsole, ?array $payload): void
    {
        $restoreEnvironment = (new WithEnv('__HYPERVEL_CONTEXT', base64_encode(serialize($payload))))($this->app);
        $previousRunningInConsole = $this->app->runningInConsole();

        try {
            $this->app->setRunningInConsole($runningInConsole);
            $events = $this->app->make('events');
            (new ConsoleServiceProvider($this->app))->boot($events);

            if ($runningInConsole) {
                $this->assertNull(Env::get('__HYPERVEL_CONTEXT'));
                $this->assertFalse(getenv('__HYPERVEL_CONTEXT'));
            }

            $hydrations = 0;
            $events->listen(ContextHydrated::class, static function () use (&$hydrations): void {
                ++$hydrations;
            });

            $events->dispatch(new BeforeHandle(new Command('context:test'), new ArrayInput([])));

            $this->assertSame(0, $hydrations);
            $this->assertFalse(Repository::hasInstance());
        } finally {
            $this->app->setRunningInConsole($previousRunningInConsole);
            $restoreEnvironment();
        }
    }

    /**
     * Provide startup contexts that must not hydrate command context.
     */
    public static function contextsWithoutProcessHydration(): array
    {
        return [
            'empty context' => [true, null],
            'HTTP server' => [false, ['data' => ['task' => serialize('concurrency')]]],
        ];
    }
}
