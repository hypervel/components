<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Console;

use Hypervel\Console\Command;
use Hypervel\Contracts\Console\Kernel;
use Hypervel\Prompts\Prompt;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Output\NullOutput;

use function Hypervel\Prompts\alert;
use function Hypervel\Prompts\autocomplete;
use function Hypervel\Prompts\confirm;
use function Hypervel\Prompts\error;
use function Hypervel\Prompts\info;
use function Hypervel\Prompts\intro;
use function Hypervel\Prompts\multisearch;
use function Hypervel\Prompts\multiselect;
use function Hypervel\Prompts\number;
use function Hypervel\Prompts\outro;
use function Hypervel\Prompts\password;
use function Hypervel\Prompts\pause;
use function Hypervel\Prompts\search;
use function Hypervel\Prompts\select;
use function Hypervel\Prompts\suggest;
use function Hypervel\Prompts\table;
use function Hypervel\Prompts\text;
use function Hypervel\Prompts\textarea;
use function Hypervel\Prompts\warning;

class PromptsAssertionTest extends TestCase
{
    public function testAssertionForTextPrompt(): void
    {
        $this->app->make(Kernel::class)->registerCommand(
            new class extends Command {
                protected ?string $signature = 'test:text';

                public function handle(): void
                {
                    $name = text('What is your name?', 'John');

                    $this->line($name);
                }
            }
        );

        $this
            ->artisan('test:text')
            ->expectsQuestion('What is your name?', 'Jane')
            ->expectsOutput('Jane');
    }

    public function testAssertionForPausePrompt(): void
    {
        $this->app->make(Kernel::class)->registerCommand(
            new class($this) extends Command {
                protected ?string $signature = 'test:pause';

                public function __construct(public PromptsAssertionTest $test)
                {
                    parent::__construct();
                }

                public function handle(): void
                {
                    $value = pause('Press any key to continue...');
                    $this->test->assertEquals(true, $value);
                }
            }
        );

        $this
            ->artisan('test:pause')
            ->expectsQuestion('Press any key to continue...', '');
    }

    public function testAssertionForTextareaPrompt(): void
    {
        $this->app->make(Kernel::class)->registerCommand(
            new class extends Command {
                protected ?string $signature = 'test:textarea';

                public function handle(): void
                {
                    $name = textarea('What is your name?', 'John');

                    $this->line($name);
                }
            }
        );

        $this
            ->artisan('test:textarea')
            ->expectsQuestion('What is your name?', 'Jane')
            ->expectsOutput('Jane');
    }

    public function testAssertionForNumberPrompt(): void
    {
        $this->app->make(Kernel::class)->registerCommand(
            new class extends Command {
                protected ?string $signature = 'test:number';

                /**
                 * Execute the console command.
                 */
                public function handle(): void
                {
                    $count = number('How many people?');

                    $this->line("There are {$count} people.");
                }
            }
        );

        $this
            ->artisan('test:number')
            ->expectsQuestion('How many people?', 5)
            ->expectsOutput('There are 5 people.');
    }

    public function testAssertionForSuggestPrompt(): void
    {
        $this->app->make(Kernel::class)->registerCommand(
            new class extends Command {
                protected ?string $signature = 'test:suggest';

                public function handle(): void
                {
                    $name = suggest('What is your name?', ['John', 'Jane']);

                    $this->line($name);
                }
            }
        );

        $this
            ->artisan('test:suggest')
            ->expectsChoice('What is your name?', 'Joe', ['John', 'Jane'])
            ->expectsOutput('Joe');
    }

    public function testAssertionForAutocompletePrompt(): void
    {
        $this->app->make(Kernel::class)->registerCommand(
            new class extends Command {
                protected ?string $signature = 'test:autocomplete';

                public function handle(): void
                {
                    $name = autocomplete('What is your name?', ['John', 'Jane']);

                    $this->line($name);
                }
            }
        );

        $this
            ->artisan('test:autocomplete')
            ->expectsChoice('What is your name?', 'Joe', ['John', 'Jane'])
            ->expectsOutput('Joe');
    }

    public function testAssertionForPasswordPrompt(): void
    {
        $this->app->make(Kernel::class)->registerCommand(
            new class extends Command {
                protected ?string $signature = 'test:password';

                public function handle(): void
                {
                    $name = password('What is your password?');

                    $this->line($name);
                }
            }
        );

        $this
            ->artisan('test:password')
            ->expectsQuestion('What is your password?', 'secret')
            ->expectsOutput('secret');
    }

    public function testAssertionForConfirmPrompt(): void
    {
        $this->app->make(Kernel::class)->registerCommand(
            new class extends Command {
                protected ?string $signature = 'test:confirm';

                public function handle(): void
                {
                    $confirmed = confirm('Is your name John?');

                    if ($confirmed) {
                        $this->line('Your name is John.');
                    } else {
                        $this->line('Your name is not John.');
                    }
                }
            }
        );

        $this
            ->artisan('test:confirm')
            ->expectsConfirmation('Is your name John?', 'no')
            ->expectsOutput('Your name is not John.');

        $this
            ->artisan('test:confirm')
            ->expectsConfirmation('Is your name John?', 'yes')
            ->expectsOutput('Your name is John.');
    }

    public function testAssertionForSelectPromptWithAList(): void
    {
        $this->app->make(Kernel::class)->registerCommand(
            new class extends Command {
                protected ?string $signature = 'test:select';

                public function handle(): void
                {
                    $name = select(
                        label: 'What is your name?',
                        options: ['John', 'Jane']
                    );

                    $this->line("Your name is {$name}.");
                }
            }
        );

        $this
            ->artisan('test:select')
            ->expectsChoice('What is your name?', 'Jane', ['John', 'Jane'])
            ->expectsOutput('Your name is Jane.');
    }

    public function testAssertionForSelectPromptWithAnAssociativeArray(): void
    {
        $this->app->make(Kernel::class)->registerCommand(
            new class extends Command {
                protected ?string $signature = 'test:select';

                public function handle(): void
                {
                    $name = select(
                        label: 'What is your name?',
                        options: ['john' => 'John', 'jane' => 'Jane']
                    );

                    $this->line("Your name is {$name}.");
                }
            }
        );

        $this
            ->artisan('test:select')
            ->expectsChoice('What is your name?', 'jane', ['john' => 'John', 'jane' => 'Jane'])
            ->expectsOutput('Your name is jane.');
    }

    public function testAlternativeAssertionForSelectPromptWithAnAssociativeArray(): void
    {
        $this->app->make(Kernel::class)->registerCommand(
            new class extends Command {
                protected ?string $signature = 'test:select';

                public function handle(): void
                {
                    $name = select(
                        label: 'What is your name?',
                        options: ['john' => 'John', 'jane' => 'Jane']
                    );

                    $this->line("Your name is {$name}.");
                }
            }
        );

        $this
            ->artisan('test:select')
            ->expectsChoice('What is your name?', 'jane', ['john', 'jane', 'John', 'Jane'])
            ->expectsOutput('Your name is jane.');
    }

    public function testAssertionForRequiredMultiselectPrompt(): void
    {
        $this->app->make(Kernel::class)->registerCommand(
            new class extends Command {
                protected ?string $signature = 'test:multiselect';

                public function handle(): void
                {
                    $names = multiselect(
                        label: 'Which names do you like?',
                        options: ['John', 'Jane', 'Sally', 'Jack'],
                        required: true
                    );

                    $this->line(sprintf('You like %s.', implode(', ', $names)));
                }
            }
        );

        $this
            ->artisan('test:multiselect')
            ->expectsChoice('Which names do you like?', ['John', 'Jane'], ['John', 'Jane', 'Sally', 'Jack'])
            ->expectsOutput('You like John, Jane.');
    }

    public function testAssertionForOptionalMultiselectPrompt(): void
    {
        $this->app->make(Kernel::class)->registerCommand(
            new class extends Command {
                protected ?string $signature = 'test:multiselect';

                public function handle(): void
                {
                    $names = multiselect(
                        label: 'Which names do you like?',
                        options: ['John', 'Jane', 'Sally', 'Jack'],
                    );

                    if (empty($names)) {
                        $this->line('You like nobody.');
                    } else {
                        $this->line(sprintf('You like %s.', implode(', ', $names)));
                    }
                }
            }
        );

        $this
            ->artisan('test:multiselect')
            ->expectsChoice('Which names do you like?', ['John', 'Jane'], ['John', 'Jane', 'Sally', 'Jack'])
            ->expectsOutput('You like John, Jane.');

        $this
            ->artisan('test:multiselect')
            ->expectsChoice('Which names do you like?', ['None'], ['John', 'Jane', 'Sally', 'Jack'])
            ->expectsOutput('You like nobody.');
    }

    public function testAssertionForSearchPrompt(): void
    {
        $this->app->make(Kernel::class)->registerCommand(
            new class extends Command {
                protected ?string $signature = 'test:search';

                public function handle(): void
                {
                    $options = collect(['John', 'Jane', 'Sally', 'Jack']);

                    $name = search(
                        label: 'What is your name?',
                        options: fn (string $value) => $value !== ''
                            ? $options->filter(fn ($title) => str_contains($title, $value))->values()->toArray()
                            : []
                    );

                    $this->line("Your name is {$name}.");
                }
            }
        );

        $this
            ->artisan('test:search')
            ->expectsSearch('What is your name?', 'Jane', 'J', ['John', 'Jane', 'Jack'])
            ->expectsOutput('Your name is Jane.');
    }

    public function testAssertionForMultisearchPrompt(): void
    {
        $this->app->make(Kernel::class)->registerCommand(
            new class extends Command {
                protected ?string $signature = 'test:multisearch';

                public function handle(): void
                {
                    $options = collect(['John', 'Jane', 'Sally', 'Jack']);

                    $names = multisearch(
                        label: 'Which names do you like?',
                        options: fn (string $value) => $value !== ''
                            ? $options->filter(fn ($title) => str_contains($title, $value))->values()->toArray()
                            : []
                    );

                    if (empty($names)) {
                        $this->line('You like nobody.');
                    } else {
                        $this->line(sprintf('You like %s.', implode(', ', $names)));
                    }
                }
            }
        );

        $this
            ->artisan('test:multisearch')
            ->expectsSearch('Which names do you like?', ['John', 'Jane'], 'J', ['John', 'Jane', 'Jack'])
            ->expectsOutput('You like John, Jane.');

        $this
            ->artisan('test:multisearch')
            ->expectsSearch('Which names do you like?', [], 'J', ['John', 'Jane', 'Jack'])
            ->expectsOutput('You like nobody.');
    }

    public function testAssertionForSelectPromptFollowedByMultisearchPrompt(): void
    {
        $this->app->make(Kernel::class)->registerCommand(
            new class extends Command {
                protected ?string $signature = 'test:select';

                public function handle(): void
                {
                    $name = select(
                        label: 'What is your name?',
                        options: ['John', 'Jane']
                    );

                    $titles = collect(['Mr', 'Mrs', 'Ms', 'Dr']);
                    $title = multisearch(
                        label: 'What is your title?',
                        options: fn (string $value) => $value !== ''
                            ? $titles->filter(fn ($title) => str_contains($title, $value))->values()->toArray()
                            : []
                    );

                    $this->line('I will refer to you ' . $title[0] . ' ' . $name . '.');
                }
            }
        );

        $this
            ->artisan('test:select')
            ->expectsChoice('What is your name?', 'Jane', ['John', 'Jane'])
            ->expectsSearch('What is your title?', ['Dr'], 'D', ['Dr'])
            ->expectsOutput('I will refer to you Dr Jane.');
    }

    public function testAssertionForSelectPromptWithDifferentOptions(): void
    {
        $this->app->make(Kernel::class)->registerCommand(
            new class extends Command {
                protected ?string $signature = 'test:select';

                public function handle(): void
                {
                    $name = select(
                        label: 'What is your name?',
                        options: ['john' => 'John', 'jane' => 'Jane']
                    );

                    $this->line("Your name is {$name}.");
                }
            }
        );

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Question "What is your name?" has different options.');

        $this
            ->artisan('test:select')
            ->expectsChoice('What is your name?', 'jane', ['john' => 'John', 'sally' => 'Sally']);
    }

    public function testAssertionForUnexpectedPrompt(): void
    {
        $this->app->make(Kernel::class)->registerCommand(
            new class extends Command {
                protected ?string $signature = 'test:confirm';

                public function handle(): void
                {
                    confirm('Is your name John?');
                }
            }
        );

        $this->expectExceptionObject(new AssertionFailedError('Unexpected question "Is your name John?" was asked.'));

        $this->artisan('test:confirm');
    }

    public function testAssertionForNotePrompts(): void
    {
        $this->app->make(Kernel::class)->registerCommand(
            new class extends Command {
                protected ?string $signature = 'test:notes';

                public function handle(): void
                {
                    intro('Starting');
                    info('Information');
                    warning('Careful');
                    error('Failure');
                    alert('Attention');
                    outro('Finished');
                }
            }
        );

        $this
            ->artisan('test:notes')
            ->expectsPromptsIntro('Starting')
            ->expectsPromptsInfo('Information')
            ->expectsPromptsWarning('Careful')
            ->expectsPromptsError('Failure')
            ->expectsPromptsAlert('Attention')
            ->expectsPromptsOutro('Finished');
    }

    public function testAssertionForMissingNotePrompt(): void
    {
        $this->app->make(Kernel::class)->registerCommand(
            new class extends Command {
                protected ?string $signature = 'test:notes';

                public function handle(): void
                {
                    info('Information');
                }
            }
        );

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Output does not contain "');

        $this
            ->artisan('test:notes')
            ->expectsPromptsWarning('Information');
    }

    public function testPromptStylesCanBeCheckedAlongsidePlainCommandOutput(): void
    {
        Artisan::command('test:plain', function () {
            warning("First line\nSecond line");
            $this->info('Finished');
        });

        $this->artisan('test:plain', ['--no-ansi' => true])
            ->expectsPromptsWarning('Second line')
            ->expectsOutputToContain('First line')
            ->expectsOutput('Finished');
    }

    public function testQuietCommandDoesNotSatisfyPromptExpectations(): void
    {
        Artisan::command('test:quiet', function () {
            info('Information');
        });

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Output does not contain "Information".');

        $this->artisan('test:quiet', ['--quiet' => true])->expectsPromptsInfo('Information');
    }

    public function testDecoratedPromptAssertionsRecognizeInlineStyles(): void
    {
        Artisan::command('test:styled', fn () => warning('Check <info>disk</info>'));

        $this->artisan('test:styled', ['--ansi' => true])
            ->expectsPromptsWarning('Check <info>disk</info>');
    }

    public function testNestedCommandRestoresOuterPromptOutput(): void
    {
        Artisan::command('test:inner', function () {
            info('Inside');
        });

        $test = $this;
        Artisan::command('test:outer', function () use ($test) {
            $test->artisan('test:inner')->expectsPromptsInfo('Inside')->run();
            warning('Outside');
            text('Continue?');
        });

        $this->artisan('test:outer')->expectsPromptsWarning('Outside')->expectsQuestion('Continue?', 'yes');
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testPlainSymfonyCommandsRestoreParentPrompts(bool $throughArtisan): void
    {
        $error = new RuntimeException('Child failed');
        $child = (new SymfonyCommand('test:symfony'))->setCode(function () use ($throughArtisan, $error): int {
            Prompt::setOutput(new NullOutput);
            table(['Child'], [['<info>Hidden</info>']]);

            if (! $throughArtisan) {
                throw $error;
            }

            return 0;
        });
        $this->app->make(Kernel::class)->registerCommand($child);

        $test = $this;
        Artisan::command('test:parent', function () use ($throughArtisan, $error, $test) {
            if ($throughArtisan) {
                Artisan::call('test:symfony');
            } else {
                try {
                    $this->call('test:symfony');
                } catch (RuntimeException $caught) {
                    $test->assertSame($error, $caught);
                }
            }

            warning('Back in the parent');
        });

        $this->artisan('test:parent')->expectsPromptsWarning('Back in the parent');
    }

    public function testFailingArtisanCallRestoresOuterPrompts(): void
    {
        $failure = new RuntimeException('Child failed');
        Artisan::command('test:inner', fn () => throw $failure);

        $test = $this;
        Artisan::command('test:outer', function () use ($test, $failure) {
            try {
                Artisan::call('test:inner');
            } catch (RuntimeException $caught) {
                $test->assertSame($failure, $caught);
                warning('Back in the parent');
                $this->line(text('Continue?'));
            }
        });

        $this->artisan('test:outer')->expectsPromptsWarning('Back in the parent')
            ->expectsQuestion('Continue?', 'yes')->expectsOutput('yes');
    }

    public function testAssertionForTablePrompt(): void
    {
        $this->app->make(Kernel::class)->registerCommand(
            new class extends Command {
                protected ?string $signature = 'test:table';

                public function handle(): void
                {
                    table(['Name', 'Email'], [
                        ['Taylor Otwell', 'taylor@hypervel.org'],
                        ['Jess Archer', 'jess@hypervel.org'],
                    ]);
                }
            }
        );

        $this
            ->artisan('test:table')
            ->expectsPromptsTable(['Name', 'Email'], [
                ['Taylor Otwell', 'taylor@hypervel.org'],
                ['Jess Archer', 'jess@hypervel.org'],
            ]);
    }

    public function testAssertionForDifferentTablePrompt(): void
    {
        $this->app->make(Kernel::class)->registerCommand(
            new class extends Command {
                protected ?string $signature = 'test:table';

                public function handle(): void
                {
                    table(['Name', 'Email'], [
                        ['Taylor Otwell', 'taylor@hypervel.org'],
                    ]);
                }
            }
        );

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Output does not contain "');

        $this
            ->artisan('test:table')
            ->expectsPromptsTable(['Name', 'Email'], [
                ['Jess Archer', 'jess@hypervel.org'],
            ]);
    }
}
