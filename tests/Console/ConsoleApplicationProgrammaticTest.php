<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console;

use Closure;
use Hypervel\Console\Application as ConsoleApplication;
use Hypervel\Console\Command;
use Hypervel\Testbench\TestCase;
use RuntimeException;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

class ConsoleApplicationProgrammaticTest extends TestCase
{
    public function testRunAndCallShareCommandInputOutputAndExitSemantics(): void
    {
        $result = $this->executeBoth([
            'argument' => 'value',
            '--flag' => 'option',
            '--no-interaction' => true,
            '--ansi' => true,
        ]);

        $this->assertSame($result['runCode'], $result['callCode']);
        $this->assertSame($result['runOutput']->fetch(), $result['callOutput']->fetch());
        $this->assertSame(ProgrammaticParityCommand::EXIT_CODE, $result['callCode']);
        $this->assertTrue($result['runOutput']->isDecorated());
        $this->assertTrue($result['callOutput']->isDecorated());
    }

    public function testRunAndCallShareQuietAndExplicitVerbositySemantics(): void
    {
        foreach ([
            ['--quiet' => true],
            ['-v' => true],
            ['-vv' => true],
            ['-vvv' => true],
        ] as $parameters) {
            $result = $this->executeBoth($parameters);

            $this->assertSame($result['runCode'], $result['callCode']);
            $this->assertSame($result['runOutput']->getVerbosity(), $result['callOutput']->getVerbosity());
            $this->assertSame($result['runOutput']->fetch(), $result['callOutput']->fetch());
        }
    }

    public function testRunAndCallShareConsoleEventOrdering(): void
    {
        $runEvents = [];
        $callEvents = [];
        [$runApplication, $runDispatcher] = $this->createConsoleApplication();
        [$callApplication, $callDispatcher] = $this->createConsoleApplication();
        $this->recordConsoleEvents($runDispatcher, $runEvents);
        $this->recordConsoleEvents($callDispatcher, $callEvents);

        $runApplication->run(
            new ArrayInput(['command' => 'test:programmatic-parity']),
            new BufferedOutput,
        );
        $callApplication->call('test:programmatic-parity', [], new BufferedOutput);

        $this->assertSame(['command', 'terminate'], $runEvents);
        $this->assertSame($runEvents, $callEvents);
    }

    public function testRunAndCallShareExceptionPropagationAndErrorEvents(): void
    {
        $runEvents = [];
        $callEvents = [];
        [$runApplication, $runDispatcher] = $this->createConsoleApplication(throwing: true);
        [$callApplication, $callDispatcher] = $this->createConsoleApplication(throwing: true);
        $this->recordConsoleEvents($runDispatcher, $runEvents);
        $this->recordConsoleEvents($callDispatcher, $callEvents);

        try {
            $runApplication->run(
                new ArrayInput(['command' => 'test:programmatic-throws']),
                new BufferedOutput,
            );
            $this->fail('Expected the command to throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('programmatic command failed', $exception->getMessage());
        }

        try {
            $callApplication->call('test:programmatic-throws', [], new BufferedOutput);
            $this->fail('Expected the command to throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('programmatic command failed', $exception->getMessage());
        }

        $this->assertSame(['command', 'error', 'terminate'], $runEvents);
        $this->assertSame($runEvents, $callEvents);
    }

    public function testRunAndCallShareHelpHandling(): void
    {
        [$runApplication] = $this->createConsoleApplication();
        [$callApplication] = $this->createConsoleApplication();
        $runOutput = new BufferedOutput;
        $callOutput = new BufferedOutput;

        $runCode = $runApplication->run(
            new ArrayInput([
                'command' => 'test:programmatic-parity',
                '--help' => true,
            ]),
            $runOutput,
        );
        $callCode = $callApplication->call('test:programmatic-parity', [
            '--help' => true,
        ], $callOutput);

        $this->assertSame($runCode, $callCode);
        $this->assertSame($runOutput->fetch(), $callOutput->fetch());
    }

    public function testCallIgnoresInheritedVerbosityWithoutMutatingGlobalStores(): void
    {
        $previousEnvironment = $_ENV['SHELL_VERBOSITY'] ?? null;
        $hadEnvironment = array_key_exists('SHELL_VERBOSITY', $_ENV);
        $previousServer = $_SERVER['SHELL_VERBOSITY'] ?? null;
        $hadServer = array_key_exists('SHELL_VERBOSITY', $_SERVER);
        $previousProcess = getenv('SHELL_VERBOSITY');
        $_ENV['SHELL_VERBOSITY'] = '2';
        $_SERVER['SHELL_VERBOSITY'] = '1';
        putenv('SHELL_VERBOSITY=3');

        try {
            [$application] = $this->createConsoleApplication();
            $output = new BufferedOutput;

            $application->call('test:programmatic-parity', [], $output);

            $this->assertSame(OutputInterface::VERBOSITY_NORMAL, $output->getVerbosity());
            $this->assertSame('2', $_ENV['SHELL_VERBOSITY']);
            $this->assertSame('1', $_SERVER['SHELL_VERBOSITY']);
            $this->assertSame('3', getenv('SHELL_VERBOSITY'));
        } finally {
            $this->restoreEnvironmentValue($_ENV, $hadEnvironment, $previousEnvironment);
            $this->restoreEnvironmentValue($_SERVER, $hadServer, $previousServer);
            putenv($previousProcess === false
                ? 'SHELL_VERBOSITY'
                : 'SHELL_VERBOSITY=' . $previousProcess);
        }
    }

    /**
     * Execute the parity command through root and programmatic paths.
     *
     * @return array{runCode: int, callCode: int, runOutput: BufferedOutput, callOutput: BufferedOutput}
     */
    private function executeBoth(array $parameters): array
    {
        [$runApplication] = $this->createConsoleApplication();
        [$callApplication] = $this->createConsoleApplication();
        $runOutput = new BufferedOutput;
        $callOutput = new BufferedOutput;

        [$runCode, $callCode] = $this->withoutShellVerbosity(fn (): array => [
            $runApplication->run(
                new ArrayInput(['command' => 'test:programmatic-parity', ...$parameters]),
                $runOutput,
            ),
            $callApplication->call(
                'test:programmatic-parity',
                $parameters,
                $callOutput,
            ),
        ]);

        return compact('runCode', 'callCode', 'runOutput', 'callOutput');
    }

    /**
     * Create an application and an observable Symfony event dispatcher.
     *
     * @return array{ConsoleApplication, EventDispatcher}
     */
    private function createConsoleApplication(bool $throwing = false): array
    {
        $application = new ConsoleApplication(
            $this->app,
            $this->app->make('events'),
            '1.0',
        );
        $dispatcher = new EventDispatcher;
        $application->setDispatcher($dispatcher);
        $application->addCommand($throwing
            ? new ProgrammaticThrowingCommand
            : new ProgrammaticParityCommand);

        return [$application, $dispatcher];
    }

    /**
     * Run a callback without inherited shell verbosity.
     *
     * @template TReturn
     * @param Closure(): TReturn $callback
     * @return TReturn
     */
    private function withoutShellVerbosity(Closure $callback): mixed
    {
        $previousEnvironment = $_ENV['SHELL_VERBOSITY'] ?? null;
        $hadEnvironment = array_key_exists('SHELL_VERBOSITY', $_ENV);
        $previousServer = $_SERVER['SHELL_VERBOSITY'] ?? null;
        $hadServer = array_key_exists('SHELL_VERBOSITY', $_SERVER);
        $previousProcess = getenv('SHELL_VERBOSITY');

        unset($_ENV['SHELL_VERBOSITY'], $_SERVER['SHELL_VERBOSITY']);
        putenv('SHELL_VERBOSITY');

        try {
            return $callback();
        } finally {
            $this->restoreEnvironmentValue($_ENV, $hadEnvironment, $previousEnvironment);
            $this->restoreEnvironmentValue($_SERVER, $hadServer, $previousServer);
            putenv($previousProcess === false
                ? 'SHELL_VERBOSITY'
                : 'SHELL_VERBOSITY=' . $previousProcess);
        }
    }

    /**
     * Record command lifecycle event ordering.
     *
     * @param array<int, string> $events
     */
    private function recordConsoleEvents(EventDispatcher $dispatcher, array &$events): void
    {
        $dispatcher->addListener(
            ConsoleEvents::COMMAND,
            static function (ConsoleCommandEvent $event) use (&$events): void {
                $events[] = 'command';
            },
        );
        $dispatcher->addListener(
            ConsoleEvents::ERROR,
            static function (ConsoleErrorEvent $event) use (&$events): void {
                $events[] = 'error';
            },
        );
        $dispatcher->addListener(
            ConsoleEvents::TERMINATE,
            static function (ConsoleTerminateEvent $event) use (&$events): void {
                $events[] = 'terminate';
            },
        );
    }

    /**
     * Restore one process-global PHP environment array entry.
     *
     * @param array<string, mixed> $store
     */
    private function restoreEnvironmentValue(
        array &$store,
        bool $existed,
        mixed $value,
    ): void {
        if ($existed) {
            $store['SHELL_VERBOSITY'] = $value;

            return;
        }

        unset($store['SHELL_VERBOSITY']);
    }
}

class ProgrammaticParityCommand extends Command
{
    public const EXIT_CODE = 17;

    protected ?string $signature = 'test:programmatic-parity {argument=default} {--flag=}';

    public function handle(): int
    {
        $this->line((string) json_encode([
            'argument' => $this->argument('argument'),
            'flag' => $this->option('flag'),
            'interactive' => $this->input->isInteractive(),
            'verbosity' => $this->output->getVerbosity(),
            'decorated' => $this->output->isDecorated(),
        ], JSON_THROW_ON_ERROR));

        return self::EXIT_CODE;
    }
}

class ProgrammaticThrowingCommand extends Command
{
    protected ?string $signature = 'test:programmatic-throws';

    public function handle(): never
    {
        throw new RuntimeException('programmatic command failed');
    }
}
