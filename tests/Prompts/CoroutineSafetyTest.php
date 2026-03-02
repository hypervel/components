<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Closure;
use Hypervel\Engine\Channel;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Prompts\ConfirmPrompt;
use Hypervel\Prompts\Prompt;
use Hypervel\Prompts\SelectPrompt;
use Hypervel\Prompts\TextPrompt;
use Hypervel\Tests\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;

use function Hypervel\Coroutine\go;

/**
 * @internal
 * @coversNothing
 */
class CoroutineSafetyTest extends TestCase
{
    use RunTestsInCoroutine;

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

    public function testOutputIsIsolatedBetweenCoroutines()
    {
        $outputA = new BufferedOutput();
        $outputB = new NullOutput();

        $channel = new Channel(2);
        $barrier = new Channel(1);

        go(function () use ($outputA, $channel, $barrier) {
            Prompt::setOutput($outputA);
            $barrier->pop(); // Wait for coroutine B to set its output
            $channel->push($this->getPromptOutput());
        });

        go(function () use ($outputB, $channel, $barrier) {
            Prompt::setOutput($outputB);
            $barrier->push(true); // Signal coroutine A
            $channel->push($this->getPromptOutput());
        });

        $results = [$channel->pop(), $channel->pop()];

        $this->assertContains($outputA, $results);
        $this->assertContains($outputB, $results);
        $this->assertNotSame($results[0], $results[1]);
    }

    public function testInteractivityIsIsolatedBetweenCoroutines()
    {
        $channel = new Channel(2);
        $barrier = new Channel(1);

        go(function () use ($channel, $barrier) {
            Prompt::interactive(true);
            $barrier->pop(); // Wait for coroutine B
            $channel->push(Prompt::isInteractive());
        });

        go(function () use ($channel, $barrier) {
            Prompt::interactive(false);
            $barrier->push(true); // Signal coroutine A
            $channel->push(Prompt::isInteractive());
        });

        $results = [$channel->pop(), $channel->pop()];

        $this->assertContains(true, $results);
        $this->assertContains(false, $results);
    }

    public function testValidateUsingIsIsolatedBetweenCoroutines()
    {
        $channel = new Channel(2);
        $barrier = new Channel(1);

        go(function () use ($channel, $barrier) {
            Prompt::validateUsing(fn () => 'error-a');
            $barrier->pop(); // Wait for coroutine B
            $callback = $this->getPromptValidateUsing();
            $channel->push($callback ? $callback() : null);
        });

        go(function () use ($channel, $barrier) {
            Prompt::validateUsing(fn () => 'error-b');
            $barrier->push(true); // Signal coroutine A
            $callback = $this->getPromptValidateUsing();
            $channel->push($callback ? $callback() : null);
        });

        $results = [$channel->pop(), $channel->pop()];

        $this->assertContains('error-a', $results);
        $this->assertContains('error-b', $results);
    }

    public function testFallbackWhenIsIsolatedBetweenCoroutines()
    {
        $channel = new Channel(2);
        $barrier = new Channel(1);

        go(function () use ($channel, $barrier) {
            Prompt::fallbackWhen(true);
            TextPrompt::fallbackUsing(fn () => 'fallback-a');
            $barrier->pop(); // Wait for coroutine B
            $channel->push(TextPrompt::shouldFallback());
        });

        go(function () use ($channel, $barrier) {
            Prompt::fallbackWhen(false);
            TextPrompt::fallbackUsing(fn () => 'fallback-b');
            $barrier->push(true); // Signal coroutine A
            $channel->push(TextPrompt::shouldFallback());
        });

        $results = [$channel->pop(), $channel->pop()];

        // Coroutine A has fallbackWhen(true), coroutine B has fallbackWhen(false)
        $this->assertContains(true, $results);
        $this->assertContains(false, $results);
    }

    public function testFallbackClosuresAreIsolatedBetweenCoroutines()
    {
        $channel = new Channel(2);
        $barrier = new Channel(1);

        go(function () use ($channel, $barrier) {
            Prompt::fallbackWhen(true);
            SelectPrompt::fallbackUsing(fn () => 'select-a');
            $barrier->pop(); // Wait for coroutine B
            $channel->push(SelectPrompt::shouldFallback());
        });

        go(function () use ($channel, $barrier) {
            Prompt::fallbackWhen(true);
            ConfirmPrompt::fallbackUsing(fn () => 'confirm-b');
            // SelectPrompt should NOT have a fallback in this coroutine
            $barrier->push(true); // Signal coroutine A
            $channel->push(SelectPrompt::shouldFallback());
        });

        $results = [$channel->pop(), $channel->pop()];

        // Coroutine A has SelectPrompt fallback, Coroutine B does not
        $this->assertContains(true, $results);
        $this->assertContains(false, $results);
    }

    public function testChildCoroutineDoesNotLeakToParent()
    {
        $channel = new Channel(1);

        // Set a value in a child coroutine
        go(function () use ($channel) {
            Prompt::setOutput(new BufferedOutput());
            $channel->push(true);
        });

        $channel->pop();

        // The parent coroutine should have its own Context, not affected by the child
        $output = $this->getPromptOutput();

        // Parent coroutine's output should not be the BufferedOutput set in the child
        $this->assertNotInstanceOf(BufferedOutput::class, $output);
    }
}
