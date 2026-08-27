<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Channel;
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

    public function testWaitsForAnInFlightAnimationBeforeSettling(): void
    {
        Prompt::fake();
        $spinner = new SpinnerAnimationFixture('Running');
        $spinner->interval = 1;

        $result = $spinner->spin(function () use ($spinner): string {
            $this->assertTrue($spinner->renderStarted->pop(1));

            Coroutine::fork(function () use ($spinner): void {
                usleep(20_000);
                $spinner->renderRelease->push(true);
            });

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertFalse($spinner->rendering);
        $renderCalls = $spinner->renderCalls;
        usleep(150_000);
        $this->assertSame($renderCalls, $spinner->renderCalls);
    }

    public function testSurfacesAnimationRenderFailureAfterSuccessfulCallback(): void
    {
        Prompt::fake();
        $failure = new RuntimeException('animation failed');
        $spinner = new SpinnerAnimationFixture('Running');
        $spinner->interval = 1;
        $spinner->renderFailure = $failure;
        $callbackRan = false;
        $thrown = null;

        try {
            $spinner->spin(function () use (&$callbackRan): void {
                $callbackRan = true;
                usleep(5_000);
            });
        } catch (RuntimeException $exception) {
            $thrown = $exception;
        }

        $this->assertSame($failure, $thrown);
        $this->assertTrue($callbackRan);
    }

    public function testCallbackFailureRemainsPrimaryWhenAnimationAlsoFails(): void
    {
        Prompt::fake();
        $callbackFailure = new RuntimeException('callback failed');
        $spinner = new SpinnerAnimationFixture('Running');
        $spinner->interval = 1;
        $spinner->renderFailure = new RuntimeException('animation failed');
        $thrown = null;

        try {
            $spinner->spin(function () use ($callbackFailure): never {
                usleep(5_000);

                throw $callbackFailure;
            });
        } catch (RuntimeException $exception) {
            $thrown = $exception;
        }

        $this->assertSame($callbackFailure, $thrown);
    }
}

class SpinnerAnimationFixture extends Spinner
{
    /** @var Channel<true> */
    public Channel $renderStarted;

    /** @var Channel<true> */
    public Channel $renderRelease;

    public int $renderCalls = 0;

    public bool $rendering = false;

    public ?RuntimeException $renderFailure = null;

    public function __construct(string $message)
    {
        parent::__construct($message);

        $this->renderStarted = new Channel(1);
        $this->renderRelease = new Channel(1);
    }

    /**
     * Render the spinner while exposing animation lifecycle checkpoints.
     */
    protected function render(): void
    {
        ++$this->renderCalls;

        if ($this->renderCalls === 1) {
            return;
        }

        if ($this->renderFailure !== null) {
            throw $this->renderFailure;
        }

        $this->rendering = true;
        $this->renderStarted->push(true);
        // This is a deadlock bound, not a rendering timing expectation.
        $released = $this->renderRelease->pop(5);
        $this->rendering = false;

        if ($released !== true) {
            throw new RuntimeException('Timed out waiting to release the spinner animation render.');
        }
    }
}
