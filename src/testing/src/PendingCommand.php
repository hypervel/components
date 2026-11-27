<?php

declare(strict_types=1);

namespace Hypervel\Testing;

use Hypervel\Console\OutputStyle;
use Hypervel\Console\PromptValidationException;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Foundation\Testing\TestCase;
use Hypervel\Prompts\Note as PromptsNote;
use Hypervel\Prompts\Prompt as BasePrompt;
use Hypervel\Prompts\Table as PromptsTable;
use Hypervel\Support\Collection;
use Hypervel\Support\Traits\Conditionable;
use Hypervel\Support\Traits\Macroable;
use Hypervel\Support\Traits\Tappable;
use Hypervel\Testing\Console\FakeBufferedOutput;
use Hypervel\Testing\Console\FakeOutputStyle;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

class PendingCommand
{
    use Conditionable;
    use Macroable;
    use Tappable;

    /**
     * The expected exit code.
     */
    protected ?int $expectedExitCode = null;

    /**
     * The unexpected exit code.
     */
    protected ?int $unexpectedExitCode = null;

    /**
     * Determine if the command has executed.
     */
    protected bool $hasExecuted = false;

    /**
     * The console output the command is run with.
     */
    protected FakeOutputStyle $consoleOutput;

    /** @var list<string> The rendered prompt substrings expected from this command. */
    private array $expectedPromptFrames = [];

    /**
     * Create a new pending console command run.
     *
     * @param TestCase $test the test being run
     * @param Container $app the application instance
     * @param string $command the command to run
     * @param array $parameters the parameters to pass to the command
     */
    public function __construct(
        public TestCase $test,
        protected Container $app,
        protected string $command,
        protected array $parameters
    ) {
    }

    /**
     * Specify an expected question that will be asked when the command runs.
     *
     * @return $this
     */
    public function expectsQuestion(string $question, mixed $answer): static
    {
        $this->test->expectedQuestions[] = [$question, $answer];

        return $this;
    }

    /**
     * Specify an expected confirmation question that will be asked when the command runs.
     *
     * @return $this
     */
    public function expectsConfirmation(string $question, string $answer = 'no'): static
    {
        return $this->expectsQuestion($question, strtolower($answer) === 'yes');
    }

    /**
     * Specify an expected choice question with expected answers that will be asked/shown when the command runs.
     *
     * @return $this
     */
    public function expectsChoice(string $question, array|string $answer, array $answers, bool $strict = false): static
    {
        $this->test->expectedChoices[$question] = [
            'expected' => $answers,
            'strict' => $strict,
        ];

        return $this->expectsQuestion($question, $answer);
    }

    /**
     * Specify an expected search question with an expected search string, followed by an expected choice question with expected answers.
     *
     * @return $this
     */
    public function expectsSearch(string $question, array|string $answer, string $search, array $answers): static
    {
        return $this
            ->expectsQuestion($question, $search)
            ->expectsChoice($question, $answer, $answers);
    }

    /**
     * Specify output that should be printed when the command runs.
     *
     * @return $this
     */
    public function expectsOutput(?string $output = null): static
    {
        if ($output === null) {
            $this->test->expectsOutput = true;

            return $this;
        }

        $this->test->expectedOutput[] = $output;

        return $this;
    }

    /**
     * Specify output that should never be printed when the command runs.
     *
     * @return $this
     */
    public function doesntExpectOutput(?string $output = null): static
    {
        if ($output === null) {
            $this->test->expectsOutput = false;

            return $this;
        }

        $this->test->unexpectedOutput[$output] = false;

        return $this;
    }

    /**
     * Specify that the given string should be contained in the command output.
     *
     * @return $this
     */
    public function expectsOutputToContain(string $string): static
    {
        $this->test->expectedOutputSubstrings[] = $string;

        return $this;
    }

    /**
     * Specify that the given string shouldn't be contained in the command output.
     *
     * @return $this
     */
    public function doesntExpectOutputToContain(string $string): static
    {
        $this->test->unexpectedOutputSubstrings[$string] = false;

        return $this;
    }

    /**
     * Specify a table that should be printed when the command runs.
     *
     * @return $this
     */
    public function expectsTable(array $headers, array|Arrayable $rows, string $tableStyle = 'default', array $columnStyles = []): static
    {
        $table = (new Table($output = new BufferedOutput))
            ->setHeaders((array) $headers)
            ->setRows($rows instanceof Arrayable ? $rows->toArray() : $rows)
            ->setStyle($tableStyle);

        foreach ($columnStyles as $columnIndex => $columnStyle) {
            $table->setColumnStyle($columnIndex, $columnStyle);
        }

        $table->render();

        $lines = array_filter(
            explode(PHP_EOL, $output->fetch())
        );

        foreach ($lines as $line) {
            $this->expectsOutput($line);
        }

        return $this;
    }

    /**
     * Specify that the given Prompts info message should be contained in the command output.
     *
     * @return $this
     */
    public function expectsPromptsInfo(string $message): static
    {
        $this->expectOutputToContainPrompt(
            new PromptsNote($message, 'info')
        );

        return $this;
    }

    /**
     * Specify that the given Prompts warning message should be contained in the command output.
     *
     * @return $this
     */
    public function expectsPromptsWarning(string $message): static
    {
        $this->expectOutputToContainPrompt(
            new PromptsNote($message, 'warning')
        );

        return $this;
    }

    /**
     * Specify that the given Prompts error message should be contained in the command output.
     *
     * @return $this
     */
    public function expectsPromptsError(string $message): static
    {
        $this->expectOutputToContainPrompt(
            new PromptsNote($message, 'error')
        );

        return $this;
    }

    /**
     * Specify that the given Prompts alert message should be contained in the command output.
     *
     * @return $this
     */
    public function expectsPromptsAlert(string $message): static
    {
        $this->expectOutputToContainPrompt(
            new PromptsNote($message, 'alert')
        );

        return $this;
    }

    /**
     * Specify that the given Prompts intro message should be contained in the command output.
     *
     * @return $this
     */
    public function expectsPromptsIntro(string $message): static
    {
        $this->expectOutputToContainPrompt(
            new PromptsNote($message, 'intro')
        );

        return $this;
    }

    /**
     * Specify that the given Prompts outro message should be contained in the command output.
     *
     * @return $this
     */
    public function expectsPromptsOutro(string $message): static
    {
        $this->expectOutputToContainPrompt(
            new PromptsNote($message, 'outro')
        );

        return $this;
    }

    /**
     * Specify a Prompts table that should be printed when the command runs.
     *
     * @param array<int, array<int, string>|string>|Collection<int, array<int, string>|string> $headers
     * @param null|array<int, array<int, string>>|Collection<int, array<int, string>> $rows
     *
     * @phpstan-param ($rows is null ? list<list<string>>|Collection<int, list<string>> : list<string|list<string>>|Collection<int, string|list<string>>) $headers
     * @return $this
     */
    public function expectsPromptsTable(array|Collection $headers, array|Collection|null $rows): static
    {
        $this->expectOutputToContainPrompt(
            new PromptsTable($headers, $rows)
        );

        return $this;
    }

    /**
     * Render the given prompt and add the output to the expectations.
     *
     * @param PromptsNote|PromptsTable $prompt
     */
    protected function expectOutputToContainPrompt(BasePrompt $prompt): void
    {
        $output = new class extends BufferedOutput {
            public string $styled = '';

            /**
             * Retain the styles that identify a rendered note on a plain terminal.
             */
            public function capturePromptFrame(string $frame): void
            {
                $this->styled .= $frame;
            }
        };

        BasePrompt::withSavedConfiguration(function () use ($output, $prompt): void {
            BasePrompt::setOutput($output);
            $prompt->display();
        });

        $this->expectedPromptFrames[] = trim($output->styled !== '' ? $output->styled : $output->fetch());
    }

    /**
     * Assert that the command has the given exit code.
     *
     * @return $this
     */
    public function assertExitCode(int $exitCode): static
    {
        $this->expectedExitCode = $exitCode;

        return $this;
    }

    /**
     * Assert that the command does not have the given exit code.
     *
     * @return $this
     */
    public function assertNotExitCode(int $exitCode): static
    {
        $this->unexpectedExitCode = $exitCode;

        return $this;
    }

    /**
     * Assert that the command has the success exit code.
     *
     * @return $this
     */
    public function assertSuccessful(): static
    {
        return $this->assertExitCode(Command::SUCCESS);
    }

    /**
     * Assert that the command has the success exit code.
     *
     * @return $this
     */
    public function assertOk(): static
    {
        return $this->assertSuccessful();
    }

    /**
     * Assert that the command does not have the success exit code.
     *
     * @return $this
     */
    public function assertFailed(): static
    {
        return $this->assertNotExitCode(Command::SUCCESS);
    }

    /**
     * Execute the command.
     */
    public function execute(): int
    {
        return $this->run();
    }

    /**
     * Execute the command.
     */
    public function run(): int
    {
        $this->hasExecuted = true;

        $previousOutput = $this->app->resolvedShared(OutputStyle::class) ? $this->app->make(OutputStyle::class) : null;
        $previousAnswerer = CoroutineContext::get(OutputStyle::QUESTION_ANSWERER_CONTEXT_KEY);
        $expectationCount = count($this->test->expectedQuestions)
            + count($this->test->expectedOutput) + count($this->test->expectedOutputSubstrings)
            + count($this->test->unexpectedOutput) + count($this->test->unexpectedOutputSubstrings)
            + (int) ($this->test->expectsOutput !== null) + count($this->expectedPromptFrames);

        try {
            $this->consoleOutput = $this->mockConsoleOutput();
            $this->flushExpectations();

            CoroutineContext::set(OutputStyle::QUESTION_ANSWERER_CONTEXT_KEY, $this->consoleOutput);

            try {
                $exitCode = $this->app
                    ->make(KernelContract::class)
                    ->call($this->command, $this->parameters, $this->consoleOutput);
            } catch (PromptValidationException) {
                $exitCode = Command::FAILURE;
            } catch (Throwable $exception) {
                if (Coroutine::causedByCancellation($exception)) {
                    throw $exception;
                }

                if ($failure = $this->consoleOutput->getOutput()->unexpectedOutputFailure()) {
                    throw new AssertionFailedError($failure, 0, $exception);
                }

                throw $exception;
            }

            if ($this->expectedExitCode !== null) {
                $this->test->assertEquals(
                    $this->expectedExitCode,
                    $exitCode,
                    "Expected status code {$this->expectedExitCode} but received {$exitCode}."
                );
            } elseif ($this->unexpectedExitCode !== null) {
                $this->test->assertNotEquals(
                    $this->unexpectedExitCode,
                    $exitCode,
                    "Unexpected status code {$this->unexpectedExitCode} was received."
                );
            }

            $this->verifyExpectations();

            // Count verified console expectations so PHPUnit recognizes console-only tests.
            $this->test->addToAssertionCount($expectationCount);

            return $exitCode;
        } finally {
            if ($previousAnswerer === null) {
                CoroutineContext::forget(OutputStyle::QUESTION_ANSWERER_CONTEXT_KEY);
            } else {
                CoroutineContext::set(OutputStyle::QUESTION_ANSWERER_CONTEXT_KEY, $previousAnswerer);
            }

            if ($previousOutput === null) {
                $this->app->forgetInstance(OutputStyle::class);
            } else {
                $this->app->instance(OutputStyle::class, $previousOutput);
            }
        }
    }

    /**
     * Debug the command.
     */
    public function dd(): never
    {
        $this->hasExecuted = true;

        $output = new BufferedOutput;
        $consoleOutput = new OutputStyle(new ArrayInput($this->parameters), $output);
        $exitCode = $this->app
            ->make(KernelContract::class)
            ->call($this->command, $this->parameters, $consoleOutput);

        dd([
            'exitCode' => $exitCode,
            'output' => $output->fetch(),
        ]);
    }

    /**
     * Determine if expected questions / choices / outputs are fulfilled.
     */
    protected function verifyExpectations(): void
    {
        if ($failure = $this->consoleOutput->failure()) {
            $this->test->fail($failure);
        }

        foreach ($this->consoleOutput->choices() as $question => $answers) {
            $assertion = $answers['strict'] ? 'assertEquals' : 'assertEqualsCanonicalizing';

            $this->test->{$assertion}(
                $answers['expected'],
                $answers['actual'],
                'Question "' . $question . '" has different options.'
            );
        }

        $buffer = $this->consoleOutput->getOutput();

        if ($failure = $buffer->failure()) {
            $this->test->fail($failure);
        }
    }

    /**
     * Fake the application's console output.
     */
    protected function mockConsoleOutput(): FakeOutputStyle
    {
        $output = new FakeOutputStyle(
            new ArrayInput($this->parameters),
            new FakeBufferedOutput(
                $this->test->expectedOutput,
                $this->test->expectedOutputSubstrings,
                array_keys($this->test->unexpectedOutput),
                array_keys($this->test->unexpectedOutputSubstrings),
                $this->test->expectsOutput,
                $this->expectedPromptFrames,
            ),
            $this->test->expectedQuestions,
            $this->test->expectedChoices,
        );

        // An instance leaves parameterized resolutions free to use a nested command's own output.
        $this->app->instance(OutputStyle::class, $output);

        return $output;
    }

    /**
     * Flush the expectations from the test case.
     */
    protected function flushExpectations(): void
    {
        $this->expectedPromptFrames = [];
        $this->test->expectsOutput = null;
        $this->test->expectedOutput = [];
        $this->test->expectedOutputSubstrings = [];
        $this->test->unexpectedOutput = [];
        $this->test->unexpectedOutputSubstrings = [];
        $this->test->expectedQuestions = [];
        $this->test->expectedChoices = [];
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::flushMacros();
    }

    /**
     * Handle the object's destruction.
     */
    public function __destruct()
    {
        if ($this->hasExecuted) {
            return;
        }

        $this->run();
    }
}
