<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console;

use Hypervel\Console\OutputStyle;
use Hypervel\Contracts\Console\Kernel;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Console\Fixtures\FakeCommandWithPromptValidation;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;
use Throwable;

class ArtisanCommandTest extends TestCase
{
    public function testConsoleCommandPasses(): void
    {
        Artisan::command('exit', fn () => 0);

        $this->artisan('exit')
            ->assertOk();
    }

    public function testConsoleCommandFails(): void
    {
        Artisan::command('exit', fn () => 1);

        $this->expectExceptionObject(new AssertionFailedError('Expected status code 0 but received 1.'));

        $this->artisan('exit')
            ->assertOk();
    }

    public function testConsoleCommandPassesWithOutput(): void
    {
        $this->registerSurveyCommand();

        $this->artisan('survey')
            ->expectsQuestion('What is your name?', 'Albert Chen')
            ->expectsQuestion('Which language do you prefer?', 'PHP')
            ->expectsOutput('Your name is Albert Chen and you prefer PHP.')
            ->doesntExpectOutput('Your name is Albert Chen and you prefer Ruby.')
            ->assertExitCode(0);
    }

    public function testConsoleCommandPassesWithRepeatingOutput(): void
    {
        $this->registerSlimCommand();

        $this->artisan('slim')
            ->expectsQuestion('Who?', 'Albert')
            ->expectsQuestion('What?', 'Albert')
            ->expectsQuestion('Huh?', 'Albert')
            ->expectsOutput('Albert')
            ->doesntExpectOutput('Chen')
            ->expectsOutput('Albert')
            ->expectsOutput('Albert')
            ->assertExitCode(0);
    }

    public function testConsoleCommandFailsFromUnexpectedOutput(): void
    {
        $this->registerSurveyCommand();

        $this->expectExceptionObject(new AssertionFailedError('Output "Your name is Albert and you prefer PHP." was printed.'));

        $this->artisan('survey')
            ->expectsQuestion('What is your name?', 'Albert')
            ->expectsQuestion('Which language do you prefer?', 'PHP')
            ->doesntExpectOutput('Your name is Albert and you prefer PHP.')
            ->assertExitCode(0);
    }

    #[TestWith([null, null])]
    #[TestWith(['expectsOutputToContain', 'My name'])]
    #[TestWith(['expectsOutput', 'My name is Albert Chen'])]
    #[TestWith(['expectsOutput', null])]
    public function testConsoleCommandFailsFromUnexpectedOutputSubstring(?string $method, ?string $output): void
    {
        $this->registerContainsCommand();

        $this->expectExceptionObject(new AssertionFailedError('Output "Albert Chen" was printed.'));

        $command = $this->artisan('contains')
            ->doesntExpectOutputToContain('Albert Chen')
            ->assertExitCode(0);

        if ($method !== null) {
            $command->{$method}($output);
        }

        $command->run();
    }

    public function testConsoleCommandFailsFromMissingOutput(): void
    {
        $this->registerSurveyCommand();

        $this->expectExceptionObject(new AssertionFailedError('Output "Your name is Albert Chen and you prefer PHP." was not printed.'));

        $this->artisan('survey')
            ->expectsQuestion('What is your name?', 'Albert Chen')
            ->expectsQuestion('Which language do you prefer?', 'Ruby')
            ->expectsOutput('Your name is Albert Chen and you prefer PHP.')
            ->assertExitCode(0);
    }

    public function testConsoleCommandFailsFromExitCodeMismatch(): void
    {
        $this->registerSurveyCommand();

        $this->expectExceptionObject(new AssertionFailedError('Expected status code 1 but received 0.'));

        $this->artisan('survey')
            ->expectsQuestion('What is your name?', 'Albert Chen')
            ->expectsQuestion('Which language do you prefer?', 'PHP')
            ->assertExitCode(1);
    }

    public function testConsoleCommandFailsFromUnOrderedOutput(): void
    {
        $this->registerSlimCommand();

        $this->expectExceptionObject(new AssertionFailedError('Output "Danger" was printed before "Chen".'));

        $this->artisan('slim')
            ->expectsQuestion('Who?', 'Albert')
            ->expectsQuestion('What?', 'Danger')
            ->expectsQuestion('Huh?', 'Chen')
            ->expectsOutput('Albert')
            ->expectsOutput('Chen')
            ->expectsOutput('Danger')
            ->assertExitCode(0);
    }

    public function testConsoleCommandPassesIfTheOutputContains(): void
    {
        $this->registerContainsCommand();

        $this->artisan('contains')
            ->expectsOutputToContain('Albert Chen')
            ->expectsOutputToContain('My name')
            ->assertExitCode(0);
    }

    public function testConsoleCommandPassesIfOutputsSomething(): void
    {
        $this->registerContainsCommand();

        $this->artisan('contains')
            ->expectsOutput()
            ->assertExitCode(0);
    }

    public function testConsoleCommandPassesIfoutputsIsSomethingAndIsTheExpectedOutput(): void
    {
        $this->registerContainsCommand();

        $this->artisan('contains')
            ->expectsOutput()
            ->expectsOutput('My name is Albert Chen')
            ->assertExitCode(0);
    }

    public function testConsoleCommandFailIfDoesntOutputSomething(): void
    {
        Artisan::command('exit {code}', fn (): int => (int) $this->argument('code'));

        $this->expectExceptionObject(new AssertionFailedError('No output was printed.'));

        $this->artisan('exit', ['code' => 0])
            ->expectsOutput()
            ->assertExitCode(0);
    }

    public function testConsoleCommandFailIfDoesntOutputSomethingAndIsNotTheExpectedOutput(): void
    {
        Artisan::command('exit {code}', fn (): int => (int) $this->argument('code'));

        $this->expectExceptionObject(new AssertionFailedError('Output "My name is Albert Chen" was not printed.'));

        $this->artisan('exit', ['code' => 0])
            ->expectsOutput()
            ->expectsOutput('My name is Albert Chen')
            ->assertExitCode(0);
    }

    public function testConsoleCommandPassesIfDoesNotOutputAnything(): void
    {
        Artisan::command('exit', fn () => 0);

        $this->artisan('exit')
            ->doesntExpectOutput()
            ->assertExitCode(0);
    }

    public function testConsoleCommandPassesIfDoesNotOutputAnythingAndIsNotTheExpectedOutput(): void
    {
        Artisan::command('exit', fn () => 0);

        $this->artisan('exit')
            ->doesntExpectOutput()
            ->doesntExpectOutput('My name is Albert Chen')
            ->assertExitCode(0);
    }

    public function testConsoleCommandPassesIfExpectsOutputAndThereIsInteractions(): void
    {
        $this->registerInteractionsCommand();

        $this->artisan('interactions', ['--no-interaction' => true])
            ->expectsOutput()
            ->expectsQuestion('What is your name?', 'Albert Chen')
            ->expectsChoice('Which language do you prefer?', 'PHP', ['PHP', 'PHP', 'PHP'])
            ->expectsConfirmation('Do you want to continue?', 'no')
            ->assertExitCode(0);
    }

    public function testConsoleCommandFailsIfDoesntExpectOutputButThereIsInteractions(): void
    {
        $this->registerInteractionsCommand();

        $this->expectExceptionObject(new AssertionFailedError('Output "+-------------+---------------------+" was printed.'));

        $this->artisan('interactions', ['--no-interaction' => true])
            ->doesntExpectOutput()
            ->expectsQuestion('What is your name?', 'Albert Chen')
            ->expectsChoice('Which language do you prefer?', 'PHP', ['PHP', 'PHP', 'PHP'])
            ->expectsConfirmation('Do you want to continue?', 'no')
            ->assertExitCode(0);
    }

    public function testConsoleCommandFailsIfDoesntExpectOutputButOutputsSomething(): void
    {
        $this->registerContainsCommand();

        $this->expectExceptionObject(new AssertionFailedError('Output "My name is Albert Chen" was printed.'));

        $this->artisan('contains')
            ->doesntExpectOutput()
            ->assertExitCode(0);
    }

    public function testConsoleCommandFailsIfDoesntExpectOutputAndDoesExpectOutput(): void
    {
        $this->registerContainsCommand();

        $this->expectExceptionObject(new AssertionFailedError('Output "My name is Albert Chen" was printed.'));

        $this->artisan('contains')
            ->doesntExpectOutput()
            ->doesntExpectOutput('My name is Albert Chen')
            ->assertExitCode(0);
    }

    public function testConsoleCommandFailsIfTheOutputDoesNotContain(): void
    {
        $this->registerContainsCommand();

        $this->expectExceptionObject(new AssertionFailedError('Output does not contain "Chen Albert".'));

        $this->artisan('contains')
            ->expectsOutputToContain('Chen Albert')
            ->assertExitCode(0);
    }

    public function testConsoleCommandFailsFromUnexpectedQuestion(): void
    {
        $this->registerSlimCommand();

        $this->expectExceptionObject(new AssertionFailedError('Unexpected question "What?" was asked.'));

        $this->artisan('slim')
            ->expectsQuestion('Who?', 'Albert');
    }

    public function testConsoleCommandFailsFromUnaskedQuestion(): void
    {
        $this->registerSlimCommand();

        $this->expectExceptionObject(new AssertionFailedError('Question "When?" was not asked.'));

        $this->artisan('slim')
            ->expectsQuestion('Who?', 'Albert')
            ->expectsQuestion('What?', 'Danger')
            ->expectsQuestion('Huh?', 'Chen')
            ->expectsQuestion('When?', 'Now');
    }

    public function testConsoleCommandFailsFromUnorderedQuestions(): void
    {
        $this->registerSlimCommand();

        $this->expectExceptionObject(new AssertionFailedError('Question "What?" was asked before "Huh?".'));

        $this->artisan('slim')
            ->expectsQuestion('Who?', 'Albert')
            ->expectsQuestion('Huh?', 'Chen')
            ->expectsQuestion('What?', 'Danger');
    }

    public function testConsoleCommandFailsFromUnexpectedQuestionTheCommandSwallows(): void
    {
        Artisan::command('swallow', function () {
            try {
                $this->ask('Who?');
            } catch (Throwable) {
            }
        });

        $this->expectExceptionObject(new AssertionFailedError('Unexpected question "Who?" was asked.'));

        $this->artisan('swallow');
    }

    public function testConsoleCommandPassesIfOneLineContainsSeveralExpectedSubstrings(): void
    {
        $this->registerContainsCommand();

        $this->artisan('contains')
            ->expectsOutput('My name is Albert Chen')
            ->expectsOutputToContain('Albert')
            ->expectsOutputToContain('Chen')
            ->assertExitCode(0);
    }

    public function testConsoleCommandFailsIfExpectedOutputContainsAnUnexpectedSubstring(): void
    {
        $this->registerContainsCommand();

        $this->expectExceptionObject(new AssertionFailedError('Output "Chen" was printed.'));

        $this->artisan('contains')
            ->expectsOutputToContain('Albert')
            ->doesntExpectOutputToContain('Chen')
            ->assertExitCode(0);
    }

    public function testConsoleCommandFailsIfOutputsSomethingUnexpected(): void
    {
        $this->registerContainsCommand();

        $this->expectExceptionObject(new AssertionFailedError('Output "My name is Albert Chen" was printed.'));

        $this->artisan('contains')
            ->expectsOutput()
            ->doesntExpectOutput('My name is Albert Chen')
            ->assertExitCode(0);
    }

    public function testConsoleCommandPassesIfExpectedOutputRepeatsAfterLaterOutput(): void
    {
        $this->registerSlimCommand();

        $this->artisan('slim')
            ->expectsQuestion('Who?', 'Albert')
            ->expectsQuestion('What?', 'Chen')
            ->expectsQuestion('Huh?', 'Albert')
            ->expectsOutput('Albert')
            ->expectsOutput('Chen')
            ->assertExitCode(0);
    }

    public function testConsoleCommandPassesWithExpectedTable(): void
    {
        $this->registerInteractionsCommand();

        $this->artisan('interactions', ['--no-interaction' => true])
            ->expectsQuestion('What is your name?', 'Albert Chen')
            ->expectsChoice('Which language do you prefer?', 'PHP', ['PHP', 'PHP', 'PHP'])
            ->expectsTable(['Name', 'Email'], [
                ['Albert Chen', 'albert@hypervel.org'],
            ])
            ->expectsConfirmation('Do you want to continue?', 'yes')
            ->assertExitCode(0);
    }

    public function testConsoleCommandFailsWithDifferentTable(): void
    {
        $this->registerInteractionsCommand();

        $this->expectExceptionObject(new AssertionFailedError('Output "| Albert Chen | albert@hypervel.net |" was not printed.'));

        $this->artisan('interactions', ['--no-interaction' => true])
            ->expectsQuestion('What is your name?', 'Albert Chen')
            ->expectsChoice('Which language do you prefer?', 'PHP', ['PHP', 'PHP', 'PHP'])
            ->expectsTable(['Name', 'Email'], [
                ['Albert Chen', 'albert@hypervel.net'],
            ])
            ->expectsConfirmation('Do you want to continue?', 'yes')
            ->assertExitCode(0);
    }

    public function testConsoleCommandFailsWithDifferentChoices(): void
    {
        $this->registerSurveyCommand();

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Question "Which language do you prefer?" has different options.');

        $this->artisan('survey')
            ->expectsQuestion('What is your name?', 'Albert Chen')
            ->expectsChoice('Which language do you prefer?', 'PHP', ['PHP', 'Ruby', 'Go']);
    }

    public function testConsoleCommandExpectationsDoNotLeakIntoNestedCommands(): void
    {
        $this->registerContainsCommand();

        $test = $this;

        Artisan::command('outer', function () use ($test) {
            $this->line('Outer');

            $outerOutput = app(OutputStyle::class);
            $test->artisan('contains')->expectsOutput('My name is Albert Chen');
            $test->assertSame($outerOutput, app(OutputStyle::class));

            $this->line('Done');
        });

        $this->artisan('outer')
            ->expectsOutput('Outer')
            ->expectsOutput('Done')
            ->assertExitCode(0);
    }

    public function testConsoleCommandOutputExpectationDoesNotCarryOverToTheNextCommand(): void
    {
        Artisan::command('exit {code}', fn (): int => (int) $this->argument('code'));
        $this->registerContainsCommand();

        $this->artisan('exit', ['code' => 0])
            ->doesntExpectOutput()
            ->assertExitCode(0);

        $this->artisan('contains')
            ->expectsOutput('My name is Albert Chen')
            ->assertExitCode(0);
    }

    public function testPendingCommandCanBeTapped(): void
    {
        Artisan::command('new-england', function () {
            $this->line('The region of New England consists of the following states:');
            $this->info('Connecticut');
            $this->info('Maine');
            $this->info('Massachusetts');
            $this->info('New Hampshire');
            $this->info('Rhode Island');
            $this->info('Vermont');
        });

        $newEngland = [
            'Connecticut',
            'Maine',
            'Massachusetts',
            'New Hampshire',
            'Rhode Island',
            'Vermont',
        ];

        $this->artisan('new-england')
            ->expectsOutput('The region of New England consists of the following states:')
            ->tap(function ($command) use ($newEngland) {
                foreach ($newEngland as $state) {
                    $command->expectsOutput($state);
                }
            })
            ->assertExitCode(0);
    }

    public function testSilentNestedCommandDoesNotWriteToOuterOutput(): void
    {
        $test = $this;
        Artisan::command('test:child', function () use ($test) {
            $test->assertSame('Yes', $this->ask('Continue?'));
            $this->line('Hidden');
        });

        Artisan::command('test:parent', function () {
            $this->callSilent('test:child');
            $this->line('Visible');
        });

        $this->artisan('test:parent')->expectsQuestion('Continue?', 'Yes')->expectsOutput('Visible')->doesntExpectOutput('Hidden');
    }

    public function testBufferedNestedCommandUsesItsBufferAndTheTestAnswers(): void
    {
        Artisan::command('test:child', function () {
            $this->line('Answer ' . $this->ask('Color?'));
        });

        $test = $this;
        Artisan::command('test:parent', function () use ($test) {
            Artisan::call('test:child', [], $buffer = new BufferedOutput);
            $test->assertSame('Answer blue' . PHP_EOL, $buffer->fetch());
        });

        $this->artisan('test:parent')->expectsQuestion('Color?', 'blue')->doesntExpectOutput();
    }

    public function testUnexpectedQuestionInBufferedNestedCommandFails(): void
    {
        Artisan::command('test:child', function () {
            $this->ask('Color?');
        });
        Artisan::command('test:parent', fn () => Artisan::call('test:child'));

        $this->expectExceptionObject(new AssertionFailedError('Unexpected question "Color?" was asked.'));
        $this->artisan('test:parent')->run();
    }

    public function testConsoleCommandReturnsCodeWithDisabledMocking(): void
    {
        Artisan::command('exit {code}', fn (): int => (int) $this->argument('code'));

        $this->withoutMockingConsoleOutput();

        $this->assertSame(0, $this->artisan('exit', ['code' => 0]));
    }

    public function testMockConsoleCommandReturnsPendingCommandWithDisabledMocking(): void
    {
        Artisan::command('exit {code}', fn (): int => (int) $this->argument('code'));

        $this->withoutMockingConsoleOutput();

        $this->mockArtisan('exit', ['code' => 0])->assertOk();
    }

    public function testRealConsoleCommandReturnsCodeWithEnabledMocking(): void
    {
        Artisan::command('exit {code}', fn (): int => (int) $this->argument('code'));

        $this->assertSame(0, $this->realArtisan('exit', ['code' => 0]));
    }

    /**
     * PromptValidationException is intentional control flow for prompt validation failures.
     * It should produce a FAILURE exit code and show the validation message, not render
     * as an unhandled exception error.
     */
    public function testPromptValidationExceptionProducesFailureWithoutErrorOutput(): void
    {
        $this->app->make(Kernel::class)->registerCommand(new FakeCommandWithPromptValidation);

        $this->artisan('fake-prompt-validation-test')
            ->expectsQuestion('What is your name?', '')
            ->expectsOutputToContain('Required!')
            ->doesntExpectOutputToContain('PromptValidationException')
            ->assertFailed();
    }

    public function testConsoleCommandFailsFromZeroAsUnexpectedOutput(): void
    {
        Artisan::command('zero-output', function (): void {
            $this->line('0');
        });

        $this->expectExceptionObject(new AssertionFailedError('Output "0" was printed.'));

        $this->artisan('zero-output')->doesntExpectOutput('0')->run();
    }

    public function testConsoleCommandFailsFromZeroAsUnexpectedOutputSubstring(): void
    {
        Artisan::command('zero-substring', function (): void {
            $this->line('value 0');
        });

        $this->expectExceptionObject(new AssertionFailedError('Output "0" was printed.'));

        $this->artisan('zero-substring')->doesntExpectOutputToContain('0')->run();
    }

    public function testForbiddenOutputIsReportedWhenTheCommandThrows(): void
    {
        $error = new RuntimeException('Command failed');
        Artisan::command('test:throw', function () use ($error) {
            $this->line('Unexpected output');

            throw $error;
        });

        try {
            $this->artisan('test:throw')->doesntExpectOutput()->run();
        } catch (AssertionFailedError $failure) {
            $this->assertSame('Output "Unexpected output" was printed.', $failure->getMessage());
            $this->assertSame($error, $failure->getPrevious());

            return;
        }

        $this->fail('The forbidden output was not reported.');
    }

    public function testCancellationEscapesAfterForbiddenOutput(): void
    {
        $cancellation = new CanceledException;
        Artisan::command('test:cancel', function () use ($cancellation) {
            $this->line('Unexpected output');

            throw $cancellation;
        });

        try {
            $this->artisan('test:cancel')->doesntExpectOutput()->run();
        } catch (CanceledException $caught) {
            $this->assertSame($cancellation, $caught);

            return;
        }

        $this->fail('The cancellation did not escape.');
    }

    public function testCommandFailureDoesNotLeakExpectationsOrOutputBinding(): void
    {
        Artisan::command('throwing-command', function () {
            throw new RuntimeException('command failed');
        });
        Artisan::command('clean-command', function () {
            $this->line('clean output');
        });

        try {
            $this->artisan('throwing-command')->doesntExpectOutput('clean output')->run();
            $this->fail('The command did not fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('command failed', $exception->getMessage());
        }

        $this->assertConsoleExpectationsFlushed();
        $this->artisan('clean-command')->expectsOutput('clean output')->assertSuccessful();
    }

    public function testExitAssertionFailureDoesNotLeakExpectationsOrOutputBinding(): void
    {
        Artisan::command('failing-exit', fn () => Command::FAILURE);
        Artisan::command('successful-exit', fn () => Command::SUCCESS);

        try {
            $this->artisan('failing-exit')->doesntExpectOutput('never printed')->assertSuccessful()->run();
            $this->fail('The exit assertion did not fail.');
        } catch (AssertionFailedError $exception) {
            $this->assertStringContainsString('Expected status code 0 but received 1.', $exception->getMessage());
        }

        $this->assertConsoleExpectationsFlushed();
        $this->artisan('successful-exit')->assertSuccessful();
    }

    public function testVerificationFailureDoesNotLeakExpectationsOrOutputBinding(): void
    {
        Artisan::command('missing-output', fn () => Command::SUCCESS);
        Artisan::command('verified-output', function () {
            $this->line('verified');
        });

        try {
            $this->artisan('missing-output')->expectsOutputToContain('missing')->run();
            $this->fail('The output assertion did not fail.');
        } catch (AssertionFailedError $exception) {
            $this->assertStringContainsString('Output does not contain "missing".', $exception->getMessage());
        }

        $this->assertConsoleExpectationsFlushed();
        $this->artisan('verified-output')->expectsOutput('verified')->assertSuccessful();
    }

    public function testOutputExpectationDoesNotRequireOutputFromTheNextCommand(): void
    {
        Artisan::command('output-command', function () {
            $this->line('expected output');
        });
        Artisan::command('silent-command', fn () => Command::SUCCESS);

        $this->artisan('output-command')->expectsOutput()->assertSuccessful();
        $this->artisan('silent-command')->assertSuccessful();
    }

    public function testDdCapturesOutputAndExecutesTheCommandOnce(): void
    {
        $directory = ParallelTesting::tempDir('PendingCommandDdFixture');
        $filesystem = new Filesystem;
        $filesystem->deleteDirectory($directory);
        $filesystem->makeDirectory($directory);
        $counter = $directory . '/executions.txt';
        $process = new Process(
            command: [PHP_BINARY, 'tests/Console/Fixtures/PendingCommandDdFixture.php'],
            cwd: dirname(__DIR__, 2),
            env: [
                'PENDING_COMMAND_DD_COUNTER' => $counter,
                'TESTBENCH_BASE_PATH' => BASE_PATH,
            ],
            timeout: 30,
        );

        try {
            $process->run();

            $failure = $process->getErrorOutput();

            $this->assertSame(1, $process->getExitCode(), $failure);
            $this->assertStringContainsString('fixture output', $process->getOutput(), $failure);
            $this->assertStringContainsString('"exitCode" => 7', $process->getOutput(), $failure);
            $this->assertSame('1', file_get_contents($counter), $failure);
        } finally {
            $filesystem->deleteDirectory($directory);
        }
    }

    protected function registerSurveyCommand(): void
    {
        Artisan::command('survey', function () {
            $name = $this->ask('What is your name?');

            $language = $this->choice('Which language do you prefer?', [
                'PHP',
                'Ruby',
                'Python',
            ]);

            $this->line("Your name is {$name} and you prefer {$language}.");
        });
    }

    protected function registerContainsCommand(): void
    {
        Artisan::command('contains', function () {
            $this->line('My name is Albert Chen');
        });
    }

    protected function registerInteractionsCommand(): void
    {
        Artisan::command('interactions', function () {
            $this->ask('What is your name?');
            $this->choice('Which language do you prefer?', [
                'PHP',
                'PHP',
                'PHP',
            ]);

            $this->table(['Name', 'Email'], [
                ['Albert Chen', 'albert@hypervel.org'],
            ]);

            $this->confirm('Do you want to continue?', true);
        });
    }

    protected function registerSlimCommand(): void
    {
        Artisan::command('slim', function () {
            $this->line($this->ask('Who?'));
            $this->line($this->ask('What?'));
            $this->line($this->ask('Huh?'));
        });
    }

    /**
     * Assert that the console expectations have been flushed.
     */
    protected function assertConsoleExpectationsFlushed(): void
    {
        $this->assertNull($this->expectsOutput);
        $this->assertSame([], $this->expectedOutput);
        $this->assertSame([], $this->expectedOutputSubstrings);
        $this->assertSame([], $this->unexpectedOutput);
        $this->assertSame([], $this->unexpectedOutputSubstrings);
        $this->assertSame([], $this->expectedQuestions);
        $this->assertSame([], $this->expectedChoices);
        $this->assertFalse($this->app->bound(OutputStyle::class));
    }
}
