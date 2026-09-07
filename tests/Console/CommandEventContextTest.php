<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console;

use Hypervel\Console\Command;
use Hypervel\Console\Events\AfterExecute;
use Hypervel\Console\Events\AfterHandle;
use Hypervel\Console\Events\BeforeHandle;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Engine\Coroutine;
use Hypervel\Testbench\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

use function Hypervel\Coroutine\run;

class CommandEventContextTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testLifecycleEventsShareTheCommandExecutionContext(): void
    {
        $eventCoroutineIds = [];
        $eventInputs = [];
        $this->app->make(Dispatcher::class)->listen(
            static function (BeforeHandle|AfterHandle|AfterExecute $event) use (&$eventCoroutineIds, &$eventInputs): void {
                $commandId = spl_object_id($event->command);
                $eventCoroutineIds[$commandId][] = Coroutine::id();

                if ($event instanceof BeforeHandle || $event instanceof AfterExecute) {
                    $eventInputs[$commandId][] = $event->input;
                }
            },
        );

        $ownCoroutine = new CommandEventContextTestCommand;
        $ownCoroutine->setHypervel($this->app);
        $ownInput = new ArrayInput([]);

        $this->assertSame(-1, Coroutine::id());
        $this->assertNull($ownCoroutine->handleCoroutineId);
        $this->assertSame(Command::SUCCESS, $ownCoroutine->run($ownInput, new NullOutput));
        $this->assertGreaterThan(0, $ownCoroutine->handleCoroutineId);
        $this->assertSame(
            array_fill(0, 3, $ownCoroutine->handleCoroutineId),
            $eventCoroutineIds[spl_object_id($ownCoroutine)],
        );
        $this->assertSame([$ownInput, $ownInput], $eventInputs[spl_object_id($ownCoroutine)]);

        $inline = new CommandEventContextTestCommand(coroutine: false);
        $inline->setHypervel($this->app);
        $inlineInput = new ArrayInput([]);

        $this->assertSame(Command::SUCCESS, $inline->run($inlineInput, new NullOutput));
        $this->assertSame(-1, $inline->handleCoroutineId);
        $this->assertSame(
            [-1, -1, -1],
            $eventCoroutineIds[spl_object_id($inline)],
        );
        $this->assertSame([$inlineInput, $inlineInput], $eventInputs[spl_object_id($inline)]);

        $nested = new CommandEventContextTestCommand;
        $nested->setHypervel($this->app);
        $nestedInput = new ArrayInput([]);
        $invocationCoroutineId = null;

        run(function () use ($nested, $nestedInput, &$invocationCoroutineId): void {
            $invocationCoroutineId = Coroutine::id();
            $nested->run($nestedInput, new NullOutput);
        });

        $this->assertGreaterThan(0, $invocationCoroutineId);
        $this->assertSame($invocationCoroutineId, $nested->handleCoroutineId);
        $this->assertSame(
            array_fill(0, 3, $invocationCoroutineId),
            $eventCoroutineIds[spl_object_id($nested)],
        );
        $this->assertSame([$nestedInput, $nestedInput], $eventInputs[spl_object_id($nested)]);
    }
}

class CommandEventContextTestCommand extends Command
{
    public ?int $handleCoroutineId = null;

    public function __construct(bool $coroutine = true)
    {
        parent::__construct();

        $this->coroutine = $coroutine;
    }

    public function handle(): int
    {
        $this->handleCoroutineId = Coroutine::id();

        return self::SUCCESS;
    }
}
