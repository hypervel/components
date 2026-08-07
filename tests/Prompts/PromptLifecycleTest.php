<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Key;
use Hypervel\Prompts\Output\BufferedConsoleOutput;
use Hypervel\Prompts\Prompt;
use Hypervel\Prompts\Spinner;
use Hypervel\Prompts\TextPrompt;
use Hypervel\Tests\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

use function Hypervel\Prompts\text;

class PromptLifecycleTest extends TestCase
{
    public function testUndecoratedInteractivePromptAppendsPlainFrames(): void
    {
        Prompt::fake(['a', 'b', Key::ENTER]);
        $output = new BufferedConsoleOutput(decorated: false);
        Prompt::setOutput($output);

        $this->assertSame('ab', text('What is your name?'));
        $this->assertStringNotContainsString("\e", $output->content());
        $this->assertGreaterThan(1, substr_count($output->content(), 'What is your name?'));
    }

    public function testTtyFailureRemainsPrimaryWhenItsNoticeCannotBeWritten(): void
    {
        Prompt::fake();
        $ttyFailure = new RuntimeException('unable to configure the terminal');
        $outputFailure = new RuntimeException('unable to write output');
        $fallbackInvoked = false;
        $output = new class($outputFailure) extends BufferedConsoleOutput {
            public function __construct(private RuntimeException $failure)
            {
                parent::__construct(decorated: true);
            }

            protected function doWrite(string $message, bool $newline): void
            {
                throw $this->failure;
            }
        };

        TextPrompt::fallbackUsing(function () use (&$fallbackInvoked): string {
            $fallbackInvoked = true;

            return 'fallback';
        });
        Prompt::terminal()
            ->shouldReceive('setTty') // @phpstan-ignore-line
            ->once()
            ->andThrow($ttyFailure);
        Prompt::setOutput($output);

        try {
            (new TextPrompt('Name'))->prompt();

            $this->fail('Expected TTY setup to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($ttyFailure, $exception);
        }

        $this->assertFalse($fallbackInvoked);
        $this->assertFalse(TextPrompt::shouldFallback());
    }

    public function testCursorOwnershipIsReleasedWhenOperationCleanupCannotRestoreIt(): void
    {
        Prompt::fake();
        $spinner = new Spinner('Running');
        $spinner->hideCursor();

        $failure = new RuntimeException('cursor restoration failed');
        $output = new class($failure) extends BufferedConsoleOutput {
            public function __construct(private RuntimeException $failure)
            {
                parent::__construct(decorated: true);
            }

            public function writeDirectly(string $message): void
            {
                throw $this->failure;
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
            (new ReflectionMethod($spinner, 'restoreTerminalState'))->invoke($spinner);

            $this->fail('Expected cursor restoration to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertTrue($ttyRestored);
        $this->assertFalse(
            (new ReflectionProperty(Prompt::class, 'cursorHidden'))->getValue(),
        );
    }
}
