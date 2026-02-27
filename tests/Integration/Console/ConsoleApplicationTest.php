<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Console;

use Hypervel\Console\Application as Artisan;
use Hypervel\Console\Command;
use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Contracts\Console\Kernel;
use Hypervel\Foundation\Console\QueuedCommand;
use Hypervel\Support\Facades\Queue;
use Hypervel\Testbench\TestCase;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * @internal
 * @coversNothing
 */
class ConsoleApplicationTest extends TestCase
{
    protected function setUp(): void
    {
        Artisan::starting(function ($artisan) {
            $artisan->resolveCommands([
                ConsoleAppFooCommandStub::class,
                ConsoleAppZondaCommandStub::class,
            ]);
        });

        parent::setUp();
    }

    public function testArtisanCallUsingCommandName()
    {
        $this->artisan('foo:bar', [
            'id' => 1,
        ])->assertExitCode(0);
    }

    public function testArtisanCallUsingCommandNameAliases()
    {
        $this->artisan('app:foobar', [
            'id' => 1,
        ])->assertExitCode(0);
    }

    public function testArtisanCallUsingCommandClass()
    {
        $this->artisan(ConsoleAppFooCommandStub::class, [
            'id' => 1,
        ])->assertExitCode(0);
    }

    public function testArtisanCallUsingCommandNameUsingAsCommandAttribute()
    {
        $this->artisan('zonda', [
            'id' => 1,
        ])->assertExitCode(0);
    }

    public function testArtisanCallUsingCommandNameAliasesUsingAsCommandAttribute()
    {
        $this->artisan('app:zonda', [
            'id' => 1,
        ])->assertExitCode(0);
    }

    public function testArtisanCallNow()
    {
        $exitCode = $this->artisan('foo:bar', [
            'id' => 1,
        ])->run();

        $this->assertSame(0, $exitCode);
    }

    public function testArtisanWithMockCallAfterCallNow()
    {
        $exitCode = $this->artisan('foo:bar', [
            'id' => 1,
        ])->run();

        $mock = $this->artisan('foo:bar', [
            'id' => 1,
        ]);

        $this->assertSame(0, $exitCode);
        $mock->assertExitCode(0);
    }

    public function testArtisanInstantiateScheduleWhenNeed()
    {
        $this->assertFalse($this->app->resolved(Schedule::class));

        $this->app[Kernel::class]->registerCommand(new ConsoleAppScheduleCommandStub());

        $this->assertFalse($this->app->resolved(Schedule::class));

        $this->artisan('foo:schedule');

        $this->assertTrue($this->app->resolved(Schedule::class));
    }

    public function testArtisanQueue()
    {
        Queue::fake();

        $this->app[Kernel::class]->queue('foo:bar', [
            'id' => 1,
        ]);

        Queue::assertPushed(QueuedCommand::class, function ($job) {
            return $job->displayName() === 'foo:bar';
        });
    }
}

class ConsoleAppFooCommandStub extends Command
{
    protected ?string $signature = 'foo:bar {id}';

    protected array $aliases = ['app:foobar'];

    public function handle()
    {
    }
}

#[AsCommand(name: 'zonda', aliases: ['app:zonda'])]
class ConsoleAppZondaCommandStub extends Command
{
    protected ?string $signature = 'zonda {id}';

    protected array $aliases = ['app:zonda'];

    public function handle()
    {
    }
}

class ConsoleAppScheduleCommandStub extends Command
{
    protected ?string $signature = 'foo:schedule';

    public function handle(Schedule $schedule)
    {
    }
}
