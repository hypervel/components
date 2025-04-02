<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Testing;

use Hypervel\Support\Facades\Artisan;
use Hypervel\Tests\Foundation\Testing\ApplicationTestCase;
use Mockery as m;
use Mockery\Exception\InvalidCountException;
use Mockery\Exception\InvalidOrderException;
use PHPUnit\Framework\AssertionFailedError;

/**
 * @internal
 * @coversNothing
 */
class ArtisanCommandTest extends ApplicationTestCase
{
    public function testConsoleCommandPasses()
    {
        Artisan::command('exit', fn () => 0);

        $this->artisan('exit')
            ->assertOk();
    }

    public function testConsoleCommandFails()
    {
        Artisan::command('exit', fn () => 1);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Expected status code 0 but received 1.');

        $this->artisan('exit')
            ->assertOk();
    }

    public function testConsoleCommandPassesWithOutput()
    {
        $this->registerSurveyCommand();

        $this->artisan('survey')
            ->expectsQuestion('What is your name?', 'Albert Chen')
            ->expectsQuestion('Which language do you prefer?', 'PHP')
            ->expectsOutput('Your name is Albert Chen and you prefer PHP.')
            ->doesntExpectOutput('Your name is Albert Chen and you prefer Ruby.')
            ->assertExitCode(0);
    }

    public function testConsoleCommandPassesWithRepeatingOutput()
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

    public function testConsoleCommandFailsFromUnexpectedOutput()
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

    public function testConsoleCommandFailsFromUnexpectedOutputSubstring()
    {
        $this->registerContainsCommand();

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Output "Albert Chen" was printed.');

        $this->artisan('contains')
            ->doesntExpectOutputToContain('Albert Chen')
            ->assertExitCode(0);
    }

    public function testConsoleCommandFailsFromMissingOutput()
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

    public function testConsoleCommandFailsFromExitCodeMismatch()
    {
        $this->registerSurveyCommand();

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Expected status code 1 but received 0.');

        $this->artisan('survey')
            ->expectsQuestion('What is your name?', 'Albert Chen')
            ->expectsQuestion('Which language do you prefer?', 'PHP')
            ->assertExitCode(1);
    }

    public function testConsoleCommandFailsFromUnOrderedOutput()
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

    public function testConsoleCommandPassesIfTheOutputContains()
    {
        $this->registerContainsCommand();

        $this->artisan('contains')
            ->expectsOutputToContain('Albert Chen')
            ->assertExitCode(0);
    }

    public function testConsoleCommandPassesIfOutputsSomething()
    {
        $this->registerContainsCommand();

        $this->artisan('contains')
            ->expectsOutput()
            ->assertExitCode(0);
    }

    public function testConsoleCommandPassesIfoutputsIsSomethingAndIsTheExpectedOutput()
    {
        $this->registerContainsCommand();

        $this->artisan('contains')
            ->expectsOutput()
            ->expectsOutput('My name is Albert Chen')
            ->assertExitCode(0);
    }

    public function testConsoleCommandFailIfDoesntOutputSomething()
    {
        Artisan::command('exit', fn () => 0);

        $this->expectException(InvalidCountException::class);

        $this->artisan('exit')
            ->expectsOutput()
            ->assertExitCode(0);

        m::close();
    }

    public function testConsoleCommandFailIfDoesntOutputSomethingAndIsNotTheExpectedOutput()
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

    public function testConsoleCommandPassesIfDoesNotOutputAnything()
    {
        Artisan::command('exit', fn () => 0);

        $this->artisan('exit')
            ->doesntExpectOutput()
            ->assertExitCode(0);
    }

    public function testConsoleCommandPassesIfDoesNotOutputAnythingAndIsNotTheExpectedOutput()
    {
        Artisan::command('exit', fn () => 0);

        $this->artisan('exit')
            ->doesntExpectOutput()
            ->doesntExpectOutput('My name is Albert Chen')
            ->assertExitCode(0);
    }

    public function testConsoleCommandPassesIfExpectsOutputAndThereIsInteractions()
    {
        $this->registerInteractionsCommand();

        $this->artisan('interactions', ['--no-interaction' => true])
            ->expectsOutput()
            ->expectsQuestion('What is your name?', 'Albert Chen')
            ->expectsChoice('Which language do you prefer?', 'PHP', ['PHP', 'PHP', 'PHP'])
            ->expectsConfirmation('Do you want to continue?', 'no')
            ->assertExitCode(0);
    }

    public function testConsoleCommandFailsIfDoesntExpectOutputButThereIsInteractions()
    {
        $this->registerInteractionsCommand();

        $this->expectException(InvalidCountException::class);

        $this->artisan('interactions', ['--no-interaction' => true])
            ->doesntExpectOutput()
            ->expectsQuestion('What is your name?', 'Albert Chen')
            ->expectsChoice('Which language do you prefer?', 'PHP', ['PHP', 'PHP', 'PHP'])
            ->expectsConfirmation('Do you want to continue?', 'no')
            ->assertExitCode(0);

        m::close();
    }

    public function testConsoleCommandFailsIfDoesntExpectOutputButOutputsSomething()
    {
        $this->registerContainsCommand();

        $this->expectException(InvalidCountException::class);

        $this->artisan('contains')
            ->doesntExpectOutput()
            ->assertExitCode(0);

        m::close();
    }

    public function testConsoleCommandFailsIfDoesntExpectOutputSomethingAndIsNotExpectOutput()
    {
        $this->registerContainsCommand();

        $this->expectException(InvalidCountException::class);

        $this->artisan('contains')
            ->doesntExpectOutput()
            ->doesntExpectOutput('My name is Albert Chen')
            ->assertExitCode(0);

        m::close();
    }

    public function testConsoleCommandFailsIfTheOutputDoesNotContain()
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

    public function testPendingCommandCanBeRapped()
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
