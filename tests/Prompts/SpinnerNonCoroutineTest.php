<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Output\BufferedConsoleOutput;
use Hypervel\Prompts\Prompt;
use Hypervel\Prompts\Spinner;
use Hypervel\Tests\TestCase;
use ReflectionProperty;
use RuntimeException;

class SpinnerNonCoroutineTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testSpinnerRendersStaticallyOutsideACoroutine(): void
    {
        Prompt::fake();
        $spinner = new Spinner('Running...');

        $result = $spinner->spin(fn () => 'done');

        $this->assertSame('done', $result);
        $this->assertTrue($spinner->static);
        $this->assertSame(0, $spinner->count);
        Prompt::assertOutputContains('Running...');
    }

    public function testEraseFailureStillRestoresTerminalState(): void
    {
        Prompt::fake();
        $eraseFailure = new RuntimeException('unable to erase the spinner');
        $output = new class($eraseFailure) extends BufferedConsoleOutput {
            public bool $failWrites = false;

            public function __construct(private RuntimeException $failure)
            {
                parent::__construct(decorated: true);
            }

            public function writeDirectly(string $message): void
            {
                if ($this->failWrites) {
                    throw $this->failure;
                }

                parent::writeDirectly($message);
            }
        };
        $ttyRestored = false;

        Prompt::setOutput($output);
        Prompt::terminal()
            ->shouldReceive('restoreTty') // @phpstan-ignore-line
            ->once()
            ->andReturnUsing(function () use (&$ttyRestored): void {
                $ttyRestored = true;
            });

        try {
            (new Spinner('Running...'))->spin(function () use ($output): void {
                $output->failWrites = true;
            });

            $this->fail('Expected spinner erasure to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($eraseFailure, $exception);
        }

        $this->assertTrue($ttyRestored);
        $this->assertFalse(
            (new ReflectionProperty(Prompt::class, 'cursorHidden'))->getValue(),
        );
    }
}
