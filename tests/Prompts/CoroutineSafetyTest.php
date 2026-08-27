<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Closure;
use Hypervel\Engine\Channel;
use Hypervel\Prompts\ConfirmPrompt;
use Hypervel\Prompts\Output\BufferedConsoleOutput;
use Hypervel\Prompts\Prompt;
use Hypervel\Prompts\SelectPrompt;
use Hypervel\Prompts\Support\InProcessLogger;
use Hypervel\Prompts\Support\Logger;
use Hypervel\Prompts\Task;
use Hypervel\Prompts\Terminal;
use Hypervel\Prompts\TextPrompt;
use Hypervel\Tests\TestCase;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;

use function Hypervel\Coroutine\parallel;
use function Hypervel\Prompts\spin;
use function Hypervel\Prompts\task;

class CoroutineSafetyTest extends TestCase
{
    /**
     * Get the protected Prompt::output() value from the current coroutine context.
     */
    private function getPromptOutput(): mixed
    {
        return Closure::bind(fn () => Prompt::output(), null, Prompt::class)();
    }

    /**
     * Get the protected Prompt::getValidateUsing() value from the current coroutine context.
     */
    private function getPromptValidateUsing(): ?Closure
    {
        return Closure::bind(fn () => Prompt::getValidateUsing(), null, Prompt::class)();
    }

    /**
     * Wait for a coroutine ordering signal.
     */
    private function waitForCoroutineSignal(Channel $barrier): void
    {
        // This is a deadlock bound, not a scheduling timing expectation.
        if ($barrier->pop(5) !== true) {
            throw new RuntimeException('Timed out waiting for a coroutine ordering signal.');
        }
    }

    public function testOutputIsIsolatedBetweenCoroutines(): void
    {
        $outputA = new BufferedOutput;
        $outputB = new NullOutput;

        $barrier = new Channel(1);

        $results = parallel([
            'a' => function () use ($outputA, $barrier): mixed {
                Prompt::setOutput($outputA);
                $this->waitForCoroutineSignal($barrier);

                return $this->getPromptOutput();
            },
            'b' => function () use ($outputB, $barrier): mixed {
                Prompt::setOutput($outputB);
                $barrier->push(true);

                return $this->getPromptOutput();
            },
        ]);

        $this->assertSame($outputA, $results['a']);
        $this->assertSame($outputB, $results['b']);
    }

    public function testInteractivityIsIsolatedBetweenCoroutines(): void
    {
        $barrier = new Channel(1);

        $results = parallel([
            'a' => function () use ($barrier): ?bool {
                Prompt::interactive(true);
                $this->waitForCoroutineSignal($barrier);

                return Prompt::isInteractive();
            },
            'b' => function () use ($barrier): ?bool {
                Prompt::interactive(false);
                $barrier->push(true);

                return Prompt::isInteractive();
            },
        ]);

        $this->assertTrue($results['a']);
        $this->assertFalse($results['b']);
    }

    public function testValidateUsingIsIsolatedBetweenCoroutines(): void
    {
        $barrier = new Channel(1);

        $results = parallel([
            'a' => function () use ($barrier): mixed {
                Prompt::validateUsing(fn () => 'error-a');
                $this->waitForCoroutineSignal($barrier);
                $callback = $this->getPromptValidateUsing();

                return $callback ? $callback() : null;
            },
            'b' => function () use ($barrier): mixed {
                Prompt::validateUsing(fn () => 'error-b');
                $barrier->push(true);
                $callback = $this->getPromptValidateUsing();

                return $callback ? $callback() : null;
            },
        ]);

        $this->assertSame('error-a', $results['a']);
        $this->assertSame('error-b', $results['b']);
    }

    public function testFallbackWhenIsIsolatedBetweenCoroutines(): void
    {
        $barrier = new Channel(1);

        $results = parallel([
            'a' => function () use ($barrier): bool {
                Prompt::fallbackWhen(true);
                TextPrompt::fallbackUsing(fn () => 'fallback-a');
                $this->waitForCoroutineSignal($barrier);

                return TextPrompt::shouldFallback();
            },
            'b' => function () use ($barrier): bool {
                Prompt::fallbackWhen(false);
                TextPrompt::fallbackUsing(fn () => 'fallback-b');
                $barrier->push(true);

                return TextPrompt::shouldFallback();
            },
        ]);

        $this->assertTrue($results['a']);
        $this->assertFalse($results['b']);
    }

    public function testFallbackClosuresAreIsolatedBetweenCoroutines(): void
    {
        $barrier = new Channel(1);

        $results = parallel([
            'a' => function () use ($barrier): bool {
                Prompt::fallbackWhen(true);
                SelectPrompt::fallbackUsing(fn () => 'select-a');
                $this->waitForCoroutineSignal($barrier);

                return SelectPrompt::shouldFallback();
            },
            'b' => function () use ($barrier): bool {
                Prompt::fallbackWhen(true);
                ConfirmPrompt::fallbackUsing(fn () => 'confirm-b');
                $barrier->push(true);

                return SelectPrompt::shouldFallback();
            },
        ]);

        $this->assertTrue($results['a']);
        $this->assertFalse($results['b']);
    }

    public function testFallbackWhenIsAdditiveWithinCoroutineContext(): void
    {
        TextPrompt::fallbackUsing(fn () => 'result');

        Prompt::fallbackWhen(true);
        $this->assertTrue(TextPrompt::shouldFallback());

        // Once enabled, fallbackWhen(false) should not disable it (additive behavior)
        Prompt::fallbackWhen(false);
        $this->assertTrue(TextPrompt::shouldFallback());
    }

    public function testChildCoroutineDoesNotLeakToParent(): void
    {
        parallel([function (): void {
            Prompt::setOutput(new BufferedOutput);
        }]);

        // The parent coroutine should have its own Context, not affected by the child
        $output = $this->getPromptOutput();

        // Parent coroutine's output should not be the BufferedOutput set in the child
        $this->assertNotInstanceOf(BufferedOutput::class, $output);
    }

    public function testSpinnerAnimationCoroutineInheritsPromptContext(): void
    {
        $output = new BufferedConsoleOutput(decorated: true);

        Prompt::setOutput($output);
        Prompt::fake();
        Prompt::setOutput($output);

        spin(function () use ($output) {
            usleep(150_000);

            $this->assertGreaterThan(1, substr_count($output->content(), 'Loading context'));

            return 'done';
        }, 'Loading context');

        $this->assertStringContainsString('Loading context', $output->content());
    }

    public function testTaskAnimationCoroutineInheritsPromptContext(): void
    {
        $output = new BufferedConsoleOutput(decorated: true);

        Prompt::setOutput($output);
        Prompt::fake();
        Prompt::setOutput($output);

        task(
            label: 'Running context',
            callback: function (Logger $logger) use ($output) {
                usleep(150_000);

                $this->assertGreaterThan(1, substr_count($output->content(), 'Running context'));

                return 'done';
            },
        );

        $this->assertStringContainsString('Running context', $output->content());
    }

    public function testTaskSubLabelStateIsIsolatedBetweenCoroutines(): void
    {
        Prompt::fake();

        $taskA = new Task(label: 'Task A');
        $taskB = new Task(label: 'Task B');
        $loggerA = new InProcessLogger($taskA);
        $loggerB = new InProcessLogger($taskB);
        $results = parallel([
            'a' => function () use ($loggerA, $taskA, $taskB): array {
                $loggerA->subLabel('Building assets');
                usleep(5000);

                return [$taskA->subLabel, $taskB->subLabel];
            },
            'b' => function () use ($loggerB, $taskA, $taskB): array {
                $loggerB->subLabel('Running migrations');
                usleep(5000);

                return [$taskA->subLabel, $taskB->subLabel];
            },
        ]);

        $this->assertSame(['Building assets', 'Running migrations'], $results['a']);
        $this->assertSame(['Building assets', 'Running migrations'], $results['b']);
    }

    public function testTerminalFlushStateResetsTerminalCaches(): void
    {
        $trueColorSupport = new ReflectionProperty(Terminal::class, 'trueColorSupport');
        $foregroundColor = new ReflectionProperty(Terminal::class, 'foregroundColor');
        $backgroundColor = new ReflectionProperty(Terminal::class, 'backgroundColor');

        $trueColorSupport->setValue(null, true);
        $foregroundColor->setValue(null, [1, 2, 3]);
        $backgroundColor->setValue(null, [4, 5, 6]);

        Terminal::flushState();

        $this->assertNull($trueColorSupport->getValue());
        $this->assertNull($foregroundColor->getValue());
        $this->assertNull($backgroundColor->getValue());
    }

    public function testPromptFlushStateResetsPromptCallbacks(): void
    {
        $cancelUsing = new ReflectionProperty(Prompt::class, 'cancelUsing');
        $revertUsing = new ReflectionProperty(Prompt::class, 'revertUsing');
        $validateUsing = new ReflectionProperty(Prompt::class, 'validateUsing');

        Prompt::cancelUsing(static fn (): null => null);
        Prompt::revertUsing(static fn (): null => null);
        // validateUsing() is coroutine-scoped in this test, so set the static slot directly.
        $validateUsing->setValue(null, static fn (): null => null);

        Prompt::flushState();

        $this->assertNull($cancelUsing->getValue());
        $this->assertNull($revertUsing->getValue());
        $this->assertNull($validateUsing->getValue());
    }
}
