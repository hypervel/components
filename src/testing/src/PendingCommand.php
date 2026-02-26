<?php

declare(strict_types=1);

namespace Hypervel\Testing;

use Hypervel\Console\Events\FailToHandle;
use Hypervel\Console\OutputStyle;
use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Testing\TestCase;
use Hypervel\Prompts\Note as PromptsNote;
use Hypervel\Prompts\Prompt as BasePrompt;
use Hypervel\Prompts\Table as PromptsTable;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Traits\Conditionable;
use Hypervel\Support\Traits\Macroable;
use Hypervel\Support\Traits\Tappable;
use Mockery;
use Mockery\Exception\NoMatchingExpectationException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Question\ChoiceQuestion;

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
     * Create a new pending console command run.
     *
     * @param TestCase $test the test being run
     * @param Application $app the application instance
     * @param string $command the command to run
     * @param array $parameters the parameters to pass to the command
     */
    public function __construct(
        public TestCase $test,
        protected Application $app,
        protected string $command,
        protected array $parameters
    ) {
    }

    /**
     * Specify an expected question that will be asked when the command runs.
     */
    public function expectsQuestion(string $question, bool|string $answer): static
    {
        $this->test->expectedQuestions[] = [$question, $answer];

        return $this;
    }

    /**
     * Specify an expected confirmation question that will be asked when the command runs.
     */
    public function expectsConfirmation(string $question, string $answer = 'no'): static
    {
        return $this->expectsQuestion($question, strtolower($answer) === 'yes');
    }

    /**
     * Specify an expected choice question with expected answers that will be asked/shown when the command runs.
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
     */
    public function expectsSearch(string $question, array|string $answer, string $search, array $answers): static
    {
        return $this
            ->expectsQuestion($question, $search)
            ->expectsChoice($question, $answer, $answers);
    }

    /**
     * Specify output that should be printed when the command runs.
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
     */
    public function expectsOutputToContain(string $string): static
    {
        $this->test->expectedOutputSubstrings[] = $string;

        return $this;
    }

    /**
     * Specify that the given string shouldn't be contained in the command output.
     */
    public function doesntExpectOutputToContain(string $string): static
    {
        $this->test->unexpectedOutputSubstrings[$string] = false;

        return $this;
    }

    /**
     * Specify a table that should be printed when the command runs.
     */
    public function expectsTable(array $headers, array|Arrayable $rows, string $tableStyle = 'default', array $columnStyles = []): static
    {
        $table = (new Table($output = new BufferedOutput()))
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
     */
    protected function expectOutputToContainPrompt(BasePrompt $prompt): void
    {
        $prompt->setOutput($output = new BufferedOutput());

        /** @var PromptsNote $prompt */
        $prompt->display();

        $this->expectsOutputToContain(trim($output->fetch()));
    }

    /**
     * Assert that the command has the given exit code.
     */
    public function assertExitCode(int $exitCode): static
    {
        $this->expectedExitCode = $exitCode;

        return $this;
    }

    /**
     * Assert that the command does not have the given exit code.
     */
    public function assertNotExitCode(int $exitCode): static
    {
        $this->unexpectedExitCode = $exitCode;

        return $this;
    }

    /**
     * Assert that the command has the success exit code.
     */
    public function assertSuccessful(): static
    {
        return $this->assertExitCode(Command::SUCCESS);
    }

    /**
     * Assert that the command has the success exit code.
     */
    public function assertOk(): static
    {
        return $this->assertSuccessful();
    }

    /**
     * Assert that the command does not have the success exit code.
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
     *
     * @throws NoMatchingExpectationException
     */
    public function run(): int
    {
        $this->hasExecuted = true;

        $mock = $this->mockConsoleOutput();

        $exception = null;
        $this->app->make(Dispatcher::class)
            ->listen(FailToHandle::class, function ($event) use (&$exception) {
                $exception = $event->getThrowable();
            });

        try {
            $exitCode = $this->app
                ->get(KernelContract::class)
                ->call($this->command, $this->parameters, $mock);
        } catch (NoMatchingExpectationException $e) {
            if ($e->getMethodName() === 'askQuestion') {
                $this->test->fail('Unexpected question "' . $e->getActualArguments()[0]->getQuestion() . '" was asked.');
            }

            throw $e;
        }

        if ($exception) {
            throw $exception;
        }

        if ($this->expectedExitCode !== null) {
            $this->test->assertEquals(
                $this->expectedExitCode,
                $exitCode,
                "Expected status code {$this->expectedExitCode} but received {$exitCode}."
            );
        } elseif (! is_null($this->unexpectedExitCode)) {
            $this->test->assertNotEquals(
                $this->unexpectedExitCode,
                $exitCode,
                "Unexpected status code {$this->unexpectedExitCode} was received."
            );
        }

        $this->verifyExpectations();
        $this->flushExpectations();

        $this->app->offsetUnset(OutputStyle::class);

        return $exitCode;
    }

    /**
     * Determine if expected questions / choices / outputs are fulfilled.
     */
    protected function verifyExpectations(): void
    {
        if (count($this->test->expectedQuestions)) {
            $this->test->fail('Question "' . Arr::first($this->test->expectedQuestions)[0] . '" was not asked.');
        }

        if (count($this->test->expectedChoices) > 0) {
            foreach ($this->test->expectedChoices as $question => $answers) {
                $assertion = $answers['strict'] ? 'assertEquals' : 'assertEqualsCanonicalizing';

                $this->test->{$assertion}(
                    $answers['expected'],
                    $answers['actual'],
                    'Question "' . $question . '" has different options.'
                );
            }
        }

        if (count($this->test->expectedOutput)) {
            $this->test->fail('Output "' . Arr::first($this->test->expectedOutput) . '" was not printed.');
        }

        if (count($this->test->expectedOutputSubstrings)) {
            $this->test->fail('Output does not contain "' . Arr::first($this->test->expectedOutputSubstrings) . '".');
        }

        if ($output = array_search(true, $this->test->unexpectedOutput)) {
            $this->test->fail('Output "' . $output . '" was printed.');
        }

        if ($output = array_search(true, $this->test->unexpectedOutputSubstrings)) {
            $this->test->fail('Output "' . $output . '" was printed.');
        }
    }

    /**
     * Mock the application's console output.
     */
    protected function mockConsoleOutput()
    {
        /* @var \Mockery\ExpectationInterface&\Mockery\MockeryInterface $mock */
        $mock = Mockery::mock(OutputStyle::class . '[askQuestion]', [
            new ArrayInput($this->parameters),
            $this->createABufferedOutputMock(),
        ]);

        foreach ($this->test->expectedQuestions as $i => $question) {
            /** @var \Mockery\Expectation $expectation */
            $expectation = $mock->shouldReceive('askQuestion');
            $expectation->once()
                ->ordered()
                ->with(Mockery::on(function ($argument) use ($question) {
                    if (isset($this->test->expectedChoices[$question[0]])) {
                        $this->test->expectedChoices[$question[0]]['actual'] = $argument instanceof ChoiceQuestion && ! array_is_list($this->test->expectedChoices[$question[0]]['expected'])
                            ? $argument->getChoices()
                            : $argument->getAutocompleterValues();
                    }

                    return $argument->getQuestion() == $question[0];
                }))
                ->andReturnUsing(function () use ($question, $i) {
                    unset($this->test->expectedQuestions[$i]);

                    return $question[1];
                });
        }

        $this->app->bind(OutputStyle::class, fn () => $mock);

        return $mock;
    }

    /**
     * Create a mock for the buffered output.
     *
     * @return \Mockery\LegacyMockInterface|\Mockery\MockInterface
     */
    private function createABufferedOutputMock()
    {
        $mock = Mockery::mock(BufferedOutput::class . '[doWrite]')
            ->shouldAllowMockingProtectedMethods()
            ->shouldIgnoreMissing();

        if ($this->test->expectsOutput === false) {
            /** @var \Mockery\Expectation $expectation */
            $expectation = $mock->shouldReceive('doWrite');
            $expectation->never();

            return $mock;
        }

        if ($this->test->expectsOutput === true
            && count($this->test->expectedOutput) === 0
            && count($this->test->expectedOutputSubstrings) === 0
        ) {
            /** @var \Mockery\Expectation $expectation */
            $expectation = $mock->shouldReceive('doWrite');
            $expectation->atLeast()->once();
        }

        foreach ($this->test->expectedOutput as $i => $output) {
            /** @var \Mockery\Expectation $expectation */
            $expectation = $mock->shouldReceive('doWrite');
            $expectation->once()
                ->ordered()
                ->with($output, Mockery::any())
                ->andReturnUsing(function () use ($i) {
                    unset($this->test->expectedOutput[$i]);
                });
        }

        foreach ($this->test->expectedOutputSubstrings as $i => $text) {
            /** @var \Mockery\Expectation $expectation */
            $expectation = $mock->shouldReceive('doWrite');
            $expectation->atLeast()
                ->times(0)
                ->withArgs(fn ($output) => str_contains($output, $text))
                ->andReturnUsing(function () use ($i) {
                    unset($this->test->expectedOutputSubstrings[$i]);
                });
        }

        foreach ($this->test->unexpectedOutput as $output => $displayed) {
            /** @var \Mockery\Expectation $expectation */
            $expectation = $mock->shouldReceive('doWrite');
            $expectation->atLeast()
                ->times(0)
                ->ordered()
                ->with($output, Mockery::any())
                ->andReturnUsing(function () use ($output) {
                    $this->test->unexpectedOutput[$output] = true;
                });
        }

        foreach ($this->test->unexpectedOutputSubstrings as $text => $displayed) {
            /** @var \Mockery\Expectation $expectation */
            $expectation = $mock->shouldReceive('doWrite');
            $expectation->atLeast()
                ->times(0)
                ->withArgs(fn ($output) => str_contains($output, $text))
                ->andReturnUsing(function () use ($text) {
                    $this->test->unexpectedOutputSubstrings[$text] = true;
                });
        }

        return $mock;
    }

    /**
     * Flush the expectations from the test case.
     */
    protected function flushExpectations(): void
    {
        $this->test->expectedOutput = [];
        $this->test->expectedOutputSubstrings = [];
        $this->test->unexpectedOutput = [];
        $this->test->unexpectedOutputSubstrings = [];
        $this->test->expectedTables = [];
        $this->test->expectedQuestions = [];
        $this->test->expectedChoices = [];
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
