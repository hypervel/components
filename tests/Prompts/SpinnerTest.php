<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Output\BufferedConsoleOutput;
use Hypervel\Prompts\Prompt;
use Hypervel\Prompts\Spinner;
use Hypervel\Tests\TestCase;
use RuntimeException;

use function Hypervel\Prompts\spin;

class SpinnerTest extends TestCase
{
    public function testSpinner(): void
    {
        Prompt::fake();

        $result = spin(function () {
            usleep(1000);

            return 'done';
        }, 'Running...');

        $this->assertSame('done', $result);

        Prompt::assertOutputContains('Running...');
    }

    public function testRendersStaticallyWhenOutputIsNotDecorated(): void
    {
        Prompt::fake();
        $output = new BufferedConsoleOutput(decorated: false);
        Prompt::setOutput($output);

        $result = spin(fn () => 'done', 'Running...');

        $this->assertSame('done', $result);
        $this->assertStringContainsString('Running...', $output->content());
        $this->assertStringNotContainsString("\e", $output->content());
    }

    public function testCallbackFailureRemainsPrimaryWhenTerminalRestorationFails(): void
    {
        Prompt::fake();
        $callbackFailure = new RuntimeException('callback failed');

        Prompt::terminal()
            ->shouldReceive('restoreTty') // @phpstan-ignore-line
            ->once()
            ->andThrow(new RuntimeException('terminal restoration failed'));

        try {
            (new Spinner('Running...'))->spin(function () use ($callbackFailure): never {
                throw $callbackFailure;
            });

            $this->fail('Expected the spinner callback to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($callbackFailure, $exception);
        }
    }

    public function testRetainedSpinnerStartsEachAnimatedOperationWithFreshState(): void
    {
        Prompt::fake();
        $spinner = new Spinner('Running...');

        $spinner->spin(fn () => usleep(220_000));
        $firstOperationTicks = $spinner->count;
        $secondOperationStartTicks = null;
        $secondOperationEndTicks = null;

        $spinner->spin(function () use ($spinner, &$secondOperationStartTicks, &$secondOperationEndTicks): void {
            $secondOperationStartTicks = $spinner->count;
            usleep(220_000);
            $secondOperationEndTicks = $spinner->count;
        });

        $this->assertGreaterThan(1, $firstOperationTicks);
        $this->assertLessThan($firstOperationTicks, $secondOperationStartTicks);
        $this->assertGreaterThan($secondOperationStartTicks, $secondOperationEndTicks);
    }

    public function testRetainedSpinnerCanAnimateAfterAStaticOperation(): void
    {
        Prompt::fake();
        $spinner = new Spinner('Running...');
        Prompt::setOutput(new BufferedConsoleOutput(decorated: false));

        $spinner->spin(fn () => null);

        $this->assertTrue($spinner->static);

        Prompt::setOutput(new BufferedConsoleOutput(decorated: true));
        $spinner->spin(function () use ($spinner): void {
            usleep(150_000);

            $this->assertFalse($spinner->static);
            $this->assertGreaterThan(0, $spinner->count);
        });
    }
}
