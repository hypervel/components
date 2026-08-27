<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Context\CoroutineContext;
use Hypervel\Prompts\Exceptions\NonInteractiveValidationException;
use Hypervel\Prompts\Key;
use Hypervel\Prompts\Output\BufferedConsoleOutput;
use Hypervel\Prompts\Output\ConsoleOutput;
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
    public function testInteractiveValidationReceivesTheSingleTransformedSubmission(): void
    {
        Prompt::fake(['a', Key::ENTER]);
        $transformCalls = 0;
        $validatedValue = null;

        Prompt::validateUsing(function (Prompt $prompt, mixed $value) use (&$validatedValue): ?string {
            $this->assertSame('rules', $prompt->validate);
            $validatedValue = $value;

            return null;
        });

        $result = (new TextPrompt(
            label: 'Value',
            validate: 'rules',
            transform: function (string $value) use (&$transformCalls): string {
                ++$transformCalls;

                return strtoupper($value);
            },
        ))->prompt();

        $this->assertSame('A', $result);
        $this->assertSame('A', $validatedValue);
        $this->assertSame(1, $transformCalls);
    }

    public function testNonInteractiveValidationReturnsTheSingleTransformedDefault(): void
    {
        Prompt::interactive(false);
        $transformCalls = 0;
        $validatedValue = null;

        $result = (new TextPrompt(
            label: 'Value',
            default: ' value ',
            validate: function (mixed $value) use (&$validatedValue): ?string {
                $validatedValue = $value;

                return null;
            },
            transform: function (string $value) use (&$transformCalls): string {
                ++$transformCalls;

                return trim($value);
            },
        ))->prompt();

        $this->assertSame('value', $result);
        $this->assertSame('value', $validatedValue);
        $this->assertSame(1, $transformCalls);
    }

    public function testEmptyIntrinsicResultContinuesToConfiguredValidation(): void
    {
        $validationCalls = 0;
        $prompt = new EmptyIntrinsicTextPrompt(
            label: 'Value',
            validate: function (string $value) use (&$validationCalls): string {
                ++$validationCalls;

                return "Rejected [{$value}].";
            },
        );

        (new ReflectionMethod(Prompt::class, 'validate'))->invoke($prompt, 'a');

        $this->assertSame(1, $validationCalls);
        $this->assertSame('error', (new ReflectionProperty(Prompt::class, 'state'))->getValue($prompt));
        $this->assertSame('Rejected [a].', (new ReflectionProperty(Prompt::class, 'error'))->getValue($prompt));
    }

    public function testCancellationReturnsTheCurrentTransformedValueWithoutASubmission(): void
    {
        Prompt::fake([Key::CTRL_C]);

        $result = (new TextPrompt(
            label: 'Value',
            default: ' value ',
            transform: trim(...),
        ))->prompt();

        $this->assertSame('value', $result);
    }

    public function testInputPromptCannotBeInvokedTwiceAfterSuccess(): void
    {
        Prompt::interactive(false);
        $prompt = new TextPrompt('Value', default: 'value');

        $this->assertSame('value', $prompt->prompt());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('This prompt has already been invoked.');

        $prompt->prompt();
    }

    public function testInputPromptCannotBeInvokedTwiceAfterFallback(): void
    {
        Prompt::fallbackWhen(true);
        TextPrompt::fallbackUsing(fn (): string => 'fallback');
        $prompt = new TextPrompt('Value');

        $this->assertSame('fallback', $prompt->prompt());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('This prompt has already been invoked.');

        $prompt->prompt();
    }

    public function testInputPromptCannotBeInvokedTwiceAfterFailure(): void
    {
        Prompt::interactive(false);
        $prompt = new TextPrompt('Value', required: true);

        try {
            $prompt->prompt();
            $this->fail('Expected non-interactive validation to fail.');
        } catch (NonInteractiveValidationException) {
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('This prompt has already been invoked.');

        $prompt->prompt();
    }

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

    public function testFlushStateForgetsPromptOverridesAndLazilyRecreatesSharedDependencies(): void
    {
        Prompt::fake();
        Prompt::validateUsing(fn (): null => null);
        CoroutineContext::set('prompts.lifecycle.sentinel', 'preserved');

        $output = new ReflectionMethod(Prompt::class, 'output');
        $validation = new ReflectionMethod(Prompt::class, 'getValidateUsing');
        $previousOutput = $output->invoke(null);
        $previousTerminal = Prompt::terminal();

        $this->assertNotNull($validation->invoke(null));

        Prompt::flushState();

        $this->assertSame('preserved', CoroutineContext::get('prompts.lifecycle.sentinel'));
        $this->assertNull($validation->invoke(null));
        $this->assertNotSame($previousOutput, $currentOutput = $output->invoke(null));
        $this->assertInstanceOf(ConsoleOutput::class, $currentOutput);
        $this->assertNotSame($previousTerminal, Prompt::terminal());
    }
}

class EmptyIntrinsicTextPrompt extends TextPrompt
{
    /**
     * Validate rules intrinsic to the prompt type.
     */
    public function validateIntrinsic(mixed $value): ?string
    {
        return '';
    }
}
