<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Output\BufferedConsoleOutput;
use Hypervel\Prompts\Progress;
use Hypervel\Prompts\Prompt;
use Hypervel\Support\Collection;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ReflectionProperty;
use RuntimeException;
use WeakReference;

use function Hypervel\Prompts\progress;

class ProgressTest extends TestCase
{
    public function testMaterializesGeneratorOnceAndPreservesValueOrder(): void
    {
        Prompt::fake();
        $iterations = 0;
        $steps = (function () use (&$iterations) {
            ++$iterations;

            yield 'duplicate' => 'first';
            yield 'duplicate' => 'second';
        })();

        $progress = new Progress('Working', $steps);
        $result = $progress->map(fn (string $step): string => strtoupper($step));

        $this->assertSame(1, $iterations);
        $this->assertSame(['first', 'second'], $progress->steps);
        $this->assertSame(['FIRST', 'SECOND'], $result);
    }

    #[DataProvider('invalidTotalsProvider')]
    public function testRejectsNonPositiveTotals(int|iterable $steps): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Progress bar must have at least one item.');

        new Progress('Working', $steps);
    }

    /**
     * Provide invalid progress totals.
     *
     * @return iterable<string, array{int|iterable<mixed>}>
     */
    public static function invalidTotalsProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'empty array' => [[]];
        yield 'empty collection' => [new Collection];
        yield 'empty generator' => [(static function () {
            yield from [];
        })()];
    }

    public function testUndecoratedProgressEmitsOnlyOneTerminalFrame(): void
    {
        Prompt::fake();
        Prompt::setOutput(new BufferedConsoleOutput(decorated: false));
        $progress = new Progress('Adding States', 2);

        $progress->start();
        $this->assertSame('', Prompt::content());

        $progress->advance();
        $this->assertSame('', Prompt::content());

        $progress->advance();
        $this->assertSame('', Prompt::content());

        $progress->finish();

        $this->assertSame(1, substr_count(Prompt::content(), 'Adding States'));
        $this->assertStringContainsString('2 / 2', Prompt::content());
        $this->assertStringNotContainsString("\e", Prompt::content());
    }

    public function testAbandonedUndecoratedProgressEmitsNothing(): void
    {
        Prompt::fake();
        Prompt::setOutput(new BufferedConsoleOutput(decorated: false));
        $progress = new Progress('Adding States', 2);

        $progress->start();
        unset($progress);

        $this->assertSame('', Prompt::content());
    }

    public function testProgressCanBeReusedWithoutStaleOperationState(): void
    {
        Prompt::fake();
        $progress = new Progress('Adding States', 2);

        $progress->start();
        $progress->advance(2);
        $progress->finish();

        $progress->start();

        $this->assertSame(0, $progress->progress);
        $this->assertSame('active', $progress->state);

        $progress->advance(2);
        $progress->finish();

        $this->assertSame(2, $progress->progress);
        $this->assertSame('submit', $progress->state);
    }

    #[RunInSeparateProcess]
    public function testRestoresExactSignalHandlerAndAsyncMode(): void
    {
        Prompt::fake();

        foreach ([SIG_IGN, static function (): void {
        }] as $index => $handler) {
            $async = $index === 1;
            pcntl_signal(SIGINT, $handler);
            pcntl_async_signals($async);

            $progress = new Progress('Working', 1);
            $progress->start();
            $progress->finish();

            $this->assertSame($handler, pcntl_signal_get_handler(SIGINT));
            $this->assertSame($async, pcntl_async_signals());
        }
    }

    #[RunInSeparateProcess]
    public function testStartFailureRestoresExactSignalAndTerminalState(): void
    {
        Prompt::fake();
        $originalHandler = pcntl_signal_get_handler(SIGINT);
        $originalAsync = pcntl_async_signals();
        $previousHandler = static function (): void {
        };
        $failure = new RuntimeException('unable to render progress');
        $output = new class($failure) extends BufferedConsoleOutput {
            /** @var list<string> */
            public array $directWrites = [];

            public function __construct(private RuntimeException $failure)
            {
                parent::__construct(decorated: true);
            }

            public function writeDirectly(string $message): void
            {
                $this->directWrites[] = $message;
            }

            protected function doWrite(string $message, bool $newline): void
            {
                throw $this->failure;
            }
        };

        pcntl_signal(SIGINT, $previousHandler);
        pcntl_async_signals(false);
        Prompt::setOutput($output);

        try {
            $progress = new Progress('Working', 1);

            try {
                $progress->start();

                $this->fail('Expected the initial progress render to fail.');
            } catch (RuntimeException $exception) {
                $this->assertSame($failure, $exception);
            }

            $this->assertSame(["\e[?25l", "\e[?25h"], $output->directWrites);
            $this->assertSame($previousHandler, pcntl_signal_get_handler(SIGINT));
            $this->assertFalse(pcntl_async_signals());
            $this->assertFalse(
                (new ReflectionProperty(Prompt::class, 'cursorHidden'))->getValue(),
            );
        } finally {
            pcntl_signal(SIGINT, $originalHandler);
            pcntl_async_signals($originalAsync);
        }
    }

    #[RunInSeparateProcess]
    public function testAbandonedManualProgressRestoresSignalAndTerminalState(): void
    {
        Prompt::fake();
        $previousHandler = static function (): void {
        };
        pcntl_signal(SIGINT, $previousHandler);
        pcntl_async_signals(false);

        $progress = new Progress('Working', 1);
        $reference = WeakReference::create($progress);
        $progress->start();

        unset($progress);
        gc_collect_cycles();

        $this->assertNull($reference->get());
        $this->assertSame($previousHandler, pcntl_signal_get_handler(SIGINT));
        $this->assertFalse(pcntl_async_signals());
        $this->assertFalse(
            (new ReflectionProperty(Prompt::class, 'cursorHidden'))->getValue(),
        );
    }

    public function testCallbackFailureRendersOneUndecoratedErrorFrameAndSettles(): void
    {
        Prompt::fake();
        Prompt::setOutput(new BufferedConsoleOutput(decorated: false));
        $progress = new Progress('Working', 1);

        try {
            $progress->map(function (): never {
                throw new RuntimeException('failed');
            });

            $this->fail('Expected the progress callback to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('failed', $exception->getMessage());
        }

        $this->assertSame(1, substr_count(Prompt::content(), 'Working'));
        $this->assertStringNotContainsString("\e", Prompt::content());
        $this->assertFalse(
            (new ReflectionProperty(Prompt::class, 'cursorHidden'))->getValue(),
        );
    }

    public function testCallbackFailureRemainsPrimaryWhenRenderingAndTerminalCleanupFail(): void
    {
        Prompt::fake();
        $callbackFailure = new RuntimeException('progress callback failed');
        $renderFailure = new RuntimeException('unable to render progress error');
        $cleanupFailure = new RuntimeException('unable to restore terminal');
        $output = new class($renderFailure) extends BufferedConsoleOutput {
            public bool $failFrames = false;

            /** @var list<string> */
            public array $directWrites = [];

            public function __construct(private RuntimeException $failure)
            {
                parent::__construct(decorated: true);
            }

            public function writeDirectly(string $message): void
            {
                $this->directWrites[] = $message;
            }

            protected function doWrite(string $message, bool $newline): void
            {
                if ($this->failFrames) {
                    throw $this->failure;
                }

                parent::doWrite($message, $newline);
            }
        };

        Prompt::setOutput($output);
        Prompt::terminal()
            ->shouldReceive('restoreTty') // @phpstan-ignore-line
            ->once()
            ->andThrow($cleanupFailure);

        try {
            (new Progress('Working', 1))->map(function () use ($output, $callbackFailure): never {
                $output->failFrames = true;

                throw $callbackFailure;
            });

            $this->fail('Expected the progress callback to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($callbackFailure, $exception);
        }

        $this->assertSame("\e[?25l", $output->directWrites[0]);
        $this->assertSame("\e[?25h", $output->directWrites[array_key_last($output->directWrites)]);
        $this->assertFalse(
            (new ReflectionProperty(Prompt::class, 'cursorHidden'))->getValue(),
        );
    }

    #[DataProvider('progressBarProvider')]
    public function testRendersProgressBar($steps): void
    {
        Prompt::fake();

        progress(
            label: 'Adding States',
            steps: $steps,
            callback: fn () => null,
        );

        Prompt::assertStrippedOutputContains(<<<'OUTPUT'
     ┌ Adding States ───────────────────────────────────────────────┐
     │                                                              │
     └─────────────────────────────────────────────────────── 0 / 4 ┘
    OUTPUT);

        Prompt::assertStrippedOutputContains(<<<'OUTPUT'
     │ ███████████████                                              │
     └─────────────────────────────────────────────────────── 1 / 4 ┘
    OUTPUT);

        Prompt::assertStrippedOutputContains(<<<'OUTPUT'
     │ ██████████████████████████████                               │
     └─────────────────────────────────────────────────────── 2 / 4 ┘
    OUTPUT);

        Prompt::assertStrippedOutputContains(<<<'OUTPUT'
     │ █████████████████████████████████████████████                │
     └─────────────────────────────────────────────────────── 3 / 4 ┘
    OUTPUT);

        Prompt::assertStrippedOutputContains(<<<'OUTPUT'
     ┌ Adding States ───────────────────────────────────────────────┐
     │ ████████████████████████████████████████████████████████████ │
     └─────────────────────────────────────────────────────── 4 / 4 ┘
    OUTPUT);
    }

    public static function progressBarProvider(): array
    {
        return [
            'array' => [['Alabama', 'Alaska', 'Arizona', 'Arkansas']],
            'integer' => [4],
            'collection' => [Collection::make(['Alabama', 'Alaska', 'Arizona', 'Arkansas'])],
        ];
    }

    public function testRendersProgressBarWithoutLabel(): void
    {
        Prompt::fake();

        progress(
            label: '',
            steps: 6,
            callback: function ($item, $progress) {
                $progress->hint((string) $item);
            }
        );

        Prompt::assertStrippedOutputContains(<<<'OUTPUT'
     ┌──────────────────────────────────────────────────────────────┐
     │                                                              │
     └─────────────────────────────────────────────────────── 0 / 6 ┘
    OUTPUT);
    }

    public function testReturnsCallbackResults(): void
    {
        Prompt::fake();

        $result = progress(
            label: 'Uppercasing States',
            steps: ['Alabama', 'Alaska', 'Arizona', 'Arkansas'],
            callback: function ($item) {
                return strtoupper($item);
            }
        );

        $this->assertSame(['ALABAMA', 'ALASKA', 'ARIZONA', 'ARKANSAS'], $result);
    }

    public function testCanUpdateLabelAndHintWhileRendering(): void
    {
        Prompt::fake();

        $states = [
            'Alabama',
            'Alaska',
            'Arizona',
            'Arkansas',
            'California',
            'Colorado',
        ];

        progress(
            label: 'Adding States',
            steps: $states,
            callback: function ($item, $progress) {
                $progress->label(strtoupper($item));
                $progress->hint(strtolower($item));
            }
        );

        Prompt::assertOutputContains('Adding States');

        foreach ($states as $state) {
            Prompt::assertOutputContains(strtoupper($state));
            Prompt::assertOutputContains(strtolower($state));
        }
    }

    public function testReturnsManualProgressBarWhenNoCallback(): void
    {
        Prompt::fake();

        $states = [
            'Alabama',
            'Alaska',
            'Arizona',
            'Arkansas',
            'California',
            'Colorado',
        ];

        $progress = progress(
            label: 'Adding States',
            steps: count($states),
        );

        $progress->start();

        foreach ($states as $state) {
            $progress->advance();
        }

        $progress->finish();

        Prompt::assertOutputContains('Adding States');
        Prompt::assertOutputDoesntContain('Alabama');
    }

    public function testCanUpdateLabelAndHintInManualMode(): void
    {
        Prompt::fake();

        $states = [
            'Alabama',
            'Alaska',
            'Arizona',
            'Arkansas',
            'California',
            'Colorado',
        ];

        $progress = progress(
            label: 'Adding States',
            steps: count($states),
        );

        $progress->start();

        foreach ($states as $state) {
            $progress
                ->label(strtoupper($state))
                ->hint(strtolower($state))
                ->advance();
        }

        $progress->finish();

        Prompt::assertOutputContains('Adding States');

        foreach ($states as $state) {
            Prompt::assertOutputContains(strtoupper($state));
            Prompt::assertOutputContains(strtolower($state));
        }
    }
}
