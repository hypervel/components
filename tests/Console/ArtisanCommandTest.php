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
use Mockery as m;
use Mockery\Exception\InvalidCountException;
use Mockery\Exception\InvalidOrderException;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Process;

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

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Expected status code 0 but received 1.');

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

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Output "Your name is Albert and you prefer PHP." was printed.');

        $this->artisan('survey')
            ->expectsQuestion('What is your name?', 'Albert')
            ->expectsQuestion('Which language do you prefer?', 'PHP')
            ->doesntExpectOutput('Your name is Albert and you prefer PHP.')
            ->assertExitCode(0);
    }

    public function testConsoleCommandFailsFromUnexpectedOutputSubstring(): void
    {
        $this->registerContainsCommand();

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Output "Albert Chen" was printed.');

        $this->artisan('contains')
            ->doesntExpectOutputToContain('Albert Chen')
            ->assertExitCode(0);
    }

    public function testConsoleCommandFailsFromMissingOutput(): void
    {
        $this->registerSurveyCommand();

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Output "Your name is Albert Chen and you prefer PHP." was not printed.');

        $this->ignoringMockOnceExceptions(function () {
            $this->artisan('survey')
                ->expectsQuestion('What is your name?', 'Albert Chen')
                ->expectsQuestion('Which language do you prefer?', 'Ruby')
                ->expectsOutput('Your name is Albert Chen and you prefer PHP.')
                ->assertExitCode(0);
        });
    }

    public function testConsoleCommandFailsFromExitCodeMismatch(): void
    {
        $this->registerSurveyCommand();

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Expected status code 1 but received 0.');

        $this->artisan('survey')
            ->expectsQuestion('What is your name?', 'Albert Chen')
            ->expectsQuestion('Which language do you prefer?', 'PHP')
            ->assertExitCode(1);
    }

    public function testConsoleCommandFailsFromUnOrderedOutput(): void
    {
        $this->registerSlimCommand();

        $this->expectException(InvalidOrderException::class);

        $this->ignoringMockOnceExceptions(function () {
            $this->artisan('slim')
                ->expectsQuestion('Who?', 'Albert')
                ->expectsQuestion('What?', 'Danger')
                ->expectsQuestion('Huh?', 'Chen')
                ->expectsOutput('Albert')
                ->expectsOutput('Chen')
                ->expectsOutput('Danger')
                ->assertExitCode(0);
        });
    }

    public function testConsoleCommandPassesIfTheOutputContains(): void
    {
        $this->registerContainsCommand();

        $this->artisan('contains')
            ->expectsOutputToContain('Albert Chen')
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
        Artisan::command('exit', fn () => 0);

        $this->expectException(InvalidCountException::class);

        $this->artisan('exit')
            ->expectsOutput()
            ->assertExitCode(0);

        $this->verifyMockeryExpectationsNow();
    }

    public function testConsoleCommandFailIfDoesntOutputSomethingAndIsNotTheExpectedOutput(): void
    {
        Artisan::command('exit', fn () => 0);

        $this->expectException(AssertionFailedError::class);

        $this->ignoringMockOnceExceptions(function () {
            $this->artisan('exit')
                ->expectsOutput()
                ->expectsOutput('My name is Albert Chen')
                ->assertExitCode(0);
        });
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

        $this->expectException(InvalidCountException::class);

        $this->artisan('interactions', ['--no-interaction' => true])
            ->doesntExpectOutput()
            ->expectsQuestion('What is your name?', 'Albert Chen')
            ->expectsChoice('Which language do you prefer?', 'PHP', ['PHP', 'PHP', 'PHP'])
            ->expectsConfirmation('Do you want to continue?', 'no')
            ->assertExitCode(0);

        $this->verifyMockeryExpectationsNow();
    }

    public function testConsoleCommandFailsIfDoesntExpectOutputButOutputsSomething(): void
    {
        $this->registerContainsCommand();

        $this->expectException(InvalidCountException::class);

        $this->artisan('contains')
            ->doesntExpectOutput()
            ->assertExitCode(0);

        $this->verifyMockeryExpectationsNow();
    }

    public function testConsoleCommandFailsIfDoesntExpectOutputSomethingAndIsNotExpectOutput(): void
    {
        $this->registerContainsCommand();

        $this->expectException(InvalidCountException::class);

        $this->artisan('contains')
            ->doesntExpectOutput()
            ->doesntExpectOutput('My name is Albert Chen')
            ->assertExitCode(0);

        $this->verifyMockeryExpectationsNow();
    }

    public function testConsoleCommandFailsIfTheOutputDoesNotContain(): void
    {
        $this->registerContainsCommand();

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Output does not contain "Chen Albert".');

        $this->ignoringMockOnceExceptions(function () {
            $this->artisan('contains')
                ->expectsOutputToContain('Chen Albert')
                ->assertExitCode(0);
        });
    }

    public function testPendingCommandCanBeRapped(): void
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

    public function testForbiddenOutputNamedStringZeroIsReported(): void
    {
        Artisan::command('zero-output', function () {
            $this->line('0');
        });

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Output "0" was printed.');

        $this->artisan('zero-output')->doesntExpectOutput('0')->run();
    }

    public function testForbiddenOutputSubstringNamedStringZeroIsReported(): void
    {
        Artisan::command('zero-substring', function () {
            $this->line('value 0');
        });

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Output "0" was printed.');

        $this->artisan('zero-substring')->doesntExpectOutputToContain('0')->run();
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

    public function testNoOutputExpectationDoesNotDisableMatchersOnTheNextCommand(): void
    {
        Artisan::command('silent-command', fn () => Command::SUCCESS);
        Artisan::command('output-command', function () {
            $this->line('expected output');
        });

        $this->artisan('silent-command')->doesntExpectOutput()->assertSuccessful();
        $this->artisan('output-command')->expectsOutput('expected output')->assertSuccessful();
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

            $this->assertSame(1, $process->getExitCode());
            $this->assertStringContainsString('fixture output', $process->getOutput());
            $this->assertStringContainsString('"exitCode" => 7', $process->getOutput());
            $this->assertSame('1', file_get_contents($counter));
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

    /**
     * Verify the PendingCommand mock expectations immediately, so an unmet
     * expectation throws here and is caught by the test's expectException().
     */
    protected function verifyMockeryExpectationsNow(): void
    {
        m::close();
    }

    protected function ignoringMockOnceExceptions(callable $callback): void
    {
        try {
            $callback();
        } finally {
            try {
                m::close();
            } catch (InvalidCountException) {
                // Ignore mock exception from PendingCommand::expectsOutput().
            }
        }
    }
}
