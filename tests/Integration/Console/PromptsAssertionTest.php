<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Console;

use Hypervel\Console\Command;
use Hypervel\Contracts\Console\Kernel;
use Hypervel\Testbench\TestCase;

use function Hypervel\Prompts\confirm;
use function Hypervel\Prompts\multisearch;
use function Hypervel\Prompts\multiselect;
use function Hypervel\Prompts\password;
use function Hypervel\Prompts\pause;
use function Hypervel\Prompts\search;
use function Hypervel\Prompts\select;
use function Hypervel\Prompts\suggest;
use function Hypervel\Prompts\text;
use function Hypervel\Prompts\textarea;

/**
 * @internal
 * @coversNothing
 */
class PromptsAssertionTest extends TestCase
{
    public function testAssertionForTextPrompt(): void
    {
        $this->app[Kernel::class]->registerCommand(
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
        $this->app[Kernel::class]->registerCommand(
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
        $this->app[Kernel::class]->registerCommand(
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

    public function testAssertionForSuggestPrompt(): void
    {
        $this->app[Kernel::class]->registerCommand(
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

    public function testAssertionForPasswordPrompt(): void
    {
        $this->app[Kernel::class]->registerCommand(
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
        $this->app[Kernel::class]->registerCommand(
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
        $this->app[Kernel::class]->registerCommand(
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
        $this->app[Kernel::class]->registerCommand(
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
        $this->app[Kernel::class]->registerCommand(
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
        $this->app[Kernel::class]->registerCommand(
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
        $this->app[Kernel::class]->registerCommand(
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
        $this->app[Kernel::class]->registerCommand(
            new class extends Command {
                protected ?string $signature = 'test:search';

                public function handle(): void
                {
                    $options = collect(['John', 'Jane', 'Sally', 'Jack']);

                    $name = search(
                        label: 'What is your name?',
                        options: fn (string $value) => strlen($value) > 0
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
        $this->app[Kernel::class]->registerCommand(
            new class extends Command {
                protected ?string $signature = 'test:multisearch';

                public function handle(): void
                {
                    $options = collect(['John', 'Jane', 'Sally', 'Jack']);

                    $names = multisearch(
                        label: 'Which names do you like?',
                        options: fn (string $value) => strlen($value) > 0
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
        $this->app[Kernel::class]->registerCommand(
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
                        options: fn (string $value) => strlen($value) > 0
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
}
