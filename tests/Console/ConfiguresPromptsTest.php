<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console;

use Closure;
use Hypervel\Console\Command;
use Hypervel\Console\OutputStyle;
use Hypervel\Console\View\Components\Factory;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Prompts\PausePrompt;
use Hypervel\Prompts\Prompt;
use Hypervel\Prompts\TextPrompt;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

use function Hypervel\Prompts\autocomplete;
use function Hypervel\Prompts\confirm;
use function Hypervel\Prompts\datatable;
use function Hypervel\Prompts\multisearch;
use function Hypervel\Prompts\multiselect;
use function Hypervel\Prompts\number;
use function Hypervel\Prompts\password;
use function Hypervel\Prompts\search;
use function Hypervel\Prompts\select;
use function Hypervel\Prompts\suggest;
use function Hypervel\Prompts\text;
use function Hypervel\Prompts\textarea;

class ConfiguresPromptsTest extends TestCase
{
    #[DataProvider('selectDataProvider')]
    public function testSelectFallback($prompt, $expectedOptions, $expectedDefault, $return, $expectedReturn)
    {
        Prompt::fallbackWhen(true);

        $command = new class($prompt) extends Command {
            public mixed $answer = null;

            public function __construct(protected mixed $prompt)
            {
                parent::__construct();
            }

            public function handle()
            {
                $this->answer = ($this->prompt)();
            }
        };

        $this->runCommand(
            $command,
            fn ($components) => $components
                ->expects('choice')
                ->with('Test', $expectedOptions, $expectedDefault)
                ->andReturn($return)
        );

        $this->assertSame($expectedReturn, $command->answer);
    }

    public static function selectDataProvider()
    {
        return [
            'list with no default' => [fn () => select('Test', ['a', 'b', 'c']), ['a', 'b', 'c'], null, 'b', 'b'],
            'numeric keys with no default' => [fn () => select('Test', [1 => 'a', 2 => 'b', 3 => 'c']), [1 => 'a', 2 => 'b', 3 => 'c'], null, '2', 2],
            'assoc with no default' => [fn () => select('Test', ['a' => 'A', 'b' => 'B', 'c' => 'C']), ['a' => 'A', 'b' => 'B', 'c' => 'C'], null, 'b', 'b'],
            'list with default' => [fn () => select('Test', ['a', 'b', 'c'], 'b'), ['a', 'b', 'c'], 'b', 'b', 'b'],
            'numeric keys with default' => [fn () => select('Test', [1 => 'a', 2 => 'b', 3 => 'c'], 2), [1 => 'a', 2 => 'b', 3 => 'c'], 2, '2', 2],
            'assoc with default' => [fn () => select('Test', ['a' => 'A', 'b' => 'B', 'c' => 'C'], 'b'), ['a' => 'A', 'b' => 'B', 'c' => 'C'], 'b', 'b', 'b'],
        ];
    }

    #[DataProvider('multiselectDataProvider')]
    public function testMultiselectFallback($prompt, $expectedOptions, $expectedDefault, $return, $expectedReturn)
    {
        Prompt::fallbackWhen(true);

        $command = new class($prompt) extends Command {
            public mixed $answer = null;

            public function __construct(protected mixed $prompt)
            {
                parent::__construct();
            }

            public function handle()
            {
                $this->answer = ($this->prompt)();
            }
        };

        $this->runCommand(
            $command,
            fn ($components) => $components
                ->expects('choice')
                ->with('Test', $expectedOptions, $expectedDefault, null, true)
                ->andReturn($return)
        );

        $this->assertSame($expectedReturn, $command->answer);
    }

    public static function multiselectDataProvider()
    {
        return [
            'list with no default' => [fn () => multiselect('Test', ['a', 'b', 'c']), ['None', 'a', 'b', 'c'], 'None', ['None'], []],
            'numeric keys with no default' => [fn () => multiselect('Test', [1 => 'a', 2 => 'b', 3 => 'c']), ['' => 'None', 1 => 'a', 2 => 'b', 3 => 'c'], 'None', [''], []],
            'assoc with no default' => [fn () => multiselect('Test', ['a' => 'A', 'b' => 'B', 'c' => 'C']), ['' => 'None', 'a' => 'A', 'b' => 'B', 'c' => 'C'], 'None', [''], []],
            'list with default' => [fn () => multiselect('Test', ['a', 'b', 'c'], ['b', 'c']), ['None', 'a', 'b', 'c'], 'b,c', ['b', 'c'], ['b', 'c']],
            'numeric keys with default' => [fn () => multiselect('Test', [1 => 'a', 2 => 'b', 3 => 'c'], [2, 3]), ['' => 'None', 1 => 'a', 2 => 'b', 3 => 'c'], '2,3', ['2', '3'], [2, 3]],
            'assoc with default' => [fn () => multiselect('Test', ['a' => 'A', 'b' => 'B', 'c' => 'C'], ['b', 'c']), ['' => 'None', 'a' => 'A', 'b' => 'B', 'c' => 'C'], 'b,c', ['b', 'c'], ['b', 'c']],
            'required list with no default' => [fn () => multiselect('Test', ['a', 'b', 'c'], required: true), ['a', 'b', 'c'], null, ['b', 'c'], ['b', 'c']],
            'required numeric keys with no default' => [fn () => multiselect('Test', [1 => 'a', 2 => 'b', 3 => 'c'], required: true), [1 => 'a', 2 => 'b', 3 => 'c'], null, ['2', '3'], [2, 3]],
            'required assoc with no default' => [fn () => multiselect('Test', ['a' => 'A', 'b' => 'B', 'c' => 'C'], required: true), ['a' => 'A', 'b' => 'B', 'c' => 'C'], null, ['b', 'c'], ['b', 'c']],
            'required list with default' => [fn () => multiselect('Test', ['a', 'b', 'c'], ['b', 'c'], required: true), ['a', 'b', 'c'], 'b,c', ['b', 'c'], ['b', 'c']],
            'required numeric keys with default' => [fn () => multiselect('Test', [1 => 'a', 2 => 'b', 3 => 'c'], [2, 3], required: true), [1 => 'a', 2 => 'b', 3 => 'c'], '2,3', ['2', '3'], [2, 3]],
            'required assoc with default' => [fn () => multiselect('Test', ['a' => 'A', 'b' => 'B', 'c' => 'C'], ['b', 'c'], required: true), ['a' => 'A', 'b' => 'B', 'c' => 'C'], 'b,c', ['b', 'c'], ['b', 'c']],
        ];
    }

    public function testNumberFallback(): void
    {
        Prompt::fallbackWhen(true);

        $answer = $this->runPrompt(
            fn () => number('How many?', default: '5'),
            fn ($components) => $components
                ->expects('ask')
                ->with('How many?', '5')
                ->andReturn('12')
        );

        $this->assertSame(12, $answer);
    }

    public function testNumberFallbackRunsNumberValidation(): void
    {
        Prompt::fallbackWhen(true);

        $command = $this->makePromptCommand(fn () => number('How many?', min: 1));

        $status = $this->runCommand(
            $command,
            function ($components) {
                $components
                    ->expects('ask')
                    ->with('How many?', null)
                    ->andReturn('0');
                $components
                    ->expects('error')
                    ->with('Must be at least 1');
            },
            runningUnitTests: true
        );

        $this->assertSame(Command::FAILURE, $status);
        $this->assertNull($command->answer);
    }

    #[DataProvider('fallbackTransformDataProvider')]
    public function testEveryFallbackTransformsItsAnswerExactlyOnce(
        Closure $prompt,
        Closure $expectations,
        mixed $expected,
    ): void {
        Prompt::fallbackWhen(true);

        $this->assertSame($expected, $this->runPrompt($prompt, $expectations));
    }

    public static function fallbackTransformDataProvider(): array
    {
        return [
            'text' => [
                fn () => text('Test', transform: fn (string $value): string => $value . '!'),
                fn ($components) => $components->expects('ask')->with('Test', null)->andReturn('value'),
                'value!',
            ],
            'textarea' => [
                fn () => textarea('Test', transform: fn (string $value): string => $value . '!'),
                fn ($components) => $components->expects('ask')->with('Test', null, multiline: true)->andReturn('value'),
                'value!',
            ],
            'number' => [
                fn () => number('Test', transform: fn (int $value): int => $value + 1),
                fn ($components) => $components->expects('ask')->with('Test', null)->andReturn('1'),
                2,
            ],
            'password' => [
                fn () => password('Test', transform: fn (string $value): string => $value . '!'),
                fn ($components) => $components->expects('secret')->with('Test')->andReturn('value'),
                'value!',
            ],
            'pause' => [
                function (): bool {
                    $prompt = new PausePrompt('Test');
                    $prompt->transform = fn (bool $value): bool => ! $value;

                    return $prompt->prompt();
                },
                fn ($components) => $components->expects('ask')->with('Test')->andReturnNull(),
                true,
            ],
            'confirm' => [
                fn () => confirm('Test', transform: fn (bool $value): bool => ! $value),
                fn ($components) => $components->expects('confirm')->with('Test', true)->andReturnTrue(),
                false,
            ],
            'select' => [
                fn () => select('Test', ['a'], transform: fn (string $value): string => $value . '!'),
                fn ($components) => $components->expects('choice')->with('Test', ['a'], null)->andReturn('a'),
                'a!',
            ],
            'multiselect' => [
                fn () => multiselect('Test', ['a'], transform: fn (array $values): array => [...$values, 'b']),
                fn ($components) => $components->expects('choice')->with('Test', ['None', 'a'], 'None', null, true)->andReturn(['a']),
                ['a', 'b'],
            ],
            'datatable' => [
                fn () => datatable(['Name'], [['Taylor']], label: 'Test', transform: fn (int $value): string => "row-{$value}"),
                fn ($components) => $components->expects('choice')->with('Test', [1 => '1: Name: Taylor'])->andReturn('1'),
                'row-0',
            ],
            'suggest' => [
                fn () => suggest('Test', ['value'], transform: fn (string $value): string => $value . '!'),
                fn ($components) => $components->expects('askWithCompletion')->with('Test', ['value'], null)->andReturn('value'),
                'value!',
            ],
            'autocomplete' => [
                fn () => autocomplete('Test', ['value'], transform: fn (string $value): string => $value . '!'),
                fn ($components) => $components->expects('askWithCompletion')->with('Test', ['value'], null)->andReturn('value'),
                'value!',
            ],
            'search' => [
                fn () => search('Test', fn (): array => ['a'], transform: fn (string $value): string => $value . '!'),
                function ($components): void {
                    $components->expects('ask')->with('Test')->andReturn('query');
                    $components->expects('choice')->with('Test', ['a'], null)->andReturn('a');
                },
                'a!',
            ],
            'multisearch' => [
                fn () => multisearch('Test', fn (): array => ['a'], transform: fn (array $values): array => [...$values, 'b']),
                function ($components): void {
                    $components->expects('ask')->with('Test')->andReturn('query');
                    $components->expects('choice')->with('Test', ['None', 'a'], 'None', null, true)->andReturn(['a']);
                },
                ['a', 'b'],
            ],
        ];
    }

    #[DataProvider('zeroDefaultDataProvider')]
    public function testFallbackReadersPreserveZeroDefaults(Closure $prompt, Closure $expectations, mixed $expected): void
    {
        Prompt::fallbackWhen(true);

        $this->assertSame($expected, $this->runPrompt($prompt, $expectations));
    }

    public static function zeroDefaultDataProvider(): array
    {
        return [
            'text' => [
                fn () => text('Test', default: '0'),
                fn ($components) => $components->expects('ask')->with('Test', '0')->andReturn('answer'),
                'answer',
            ],
            'textarea' => [
                fn () => textarea('Test', default: '0'),
                fn ($components) => $components->expects('ask')->with('Test', '0', multiline: true)->andReturn('answer'),
                'answer',
            ],
            'number' => [
                fn () => number('Test', default: 0),
                fn ($components) => $components->expects('ask')->with('Test', 0)->andReturn('1'),
                1,
            ],
            'suggest' => [
                fn () => suggest('Test', ['answer'], default: '0'),
                fn ($components) => $components->expects('askWithCompletion')->with('Test', ['answer'], '0')->andReturn('answer'),
                'answer',
            ],
            'autocomplete' => [
                fn () => autocomplete('Test', ['answer'], default: '0'),
                fn ($components) => $components->expects('askWithCompletion')->with('Test', ['answer'], '0')->andReturn('answer'),
                'answer',
            ],
        ];
    }

    public function testFrameworkRulesValidateTheTransformedFallbackAnswer(): void
    {
        Prompt::fallbackWhen(true);

        $command = new class extends Command {
            public mixed $answer = null;

            public mixed $validatedValue = null;

            public mixed $validatedRules = null;

            public function handle(): void
            {
                $this->answer = text(
                    'Test',
                    validate: ['required'],
                    transform: fn (string $value): string => $value . '!',
                );
            }

            protected function validatePrompt(mixed $value, mixed $rules): ?string
            {
                $this->validatedValue = $value;
                $this->validatedRules = $rules;

                return null;
            }
        };

        $this->runCommand(
            $command,
            fn ($components) => $components->expects('ask')->with('Test', null)->andReturn('value'),
        );

        $this->assertSame('value!', $command->answer);
        $this->assertSame('value!', $command->validatedValue);
        $this->assertSame(['required'], $command->validatedRules);
    }

    public function testIntrinsicFallbackValidationPrecedesFrameworkRules(): void
    {
        Prompt::fallbackWhen(true);

        $command = new class extends Command {
            public int $validationCalls = 0;

            public function handle(): void
            {
                number('Test', validate: ['integer'], min: 1);
            }

            protected function validatePrompt(mixed $value, mixed $rules): ?string
            {
                ++$this->validationCalls;

                return null;
            }
        };

        $status = $this->runCommand(
            $command,
            function ($components): void {
                $components->expects('ask')->with('Test', null)->andReturn('0');
                $components->expects('error')->with('Must be at least 1');
            },
            runningUnitTests: true,
        );

        $this->assertSame(Command::FAILURE, $status);
        $this->assertSame(0, $command->validationCalls);
    }

    public function testEmptyIntrinsicFallbackResultContinuesToFrameworkValidation(): void
    {
        $command = new class extends Command {
            public int $validationCalls = 0;

            protected function validatePrompt(mixed $value, mixed $rules): ?string
            {
                ++$this->validationCalls;

                return "Rejected [{$value}].";
            }
        };
        $prompt = new ConfiguresPromptsEmptyIntrinsicTextPrompt('Test', validate: ['required']);

        $error = (new ReflectionMethod($command, 'validateFallbackPrompt'))->invoke($command, $prompt, 'value');

        $this->assertSame('Rejected [value].', $error);
        $this->assertSame(1, $command->validationCalls);
    }

    #[DataProvider('requiredFallbackDataProvider')]
    public function testFallbackRequiredValidationUsesInteractiveEmptySemantics(
        bool|string $required,
        mixed $value,
        string $message,
    ): void {
        Prompt::fallbackWhen(true);

        $command = new class($required, $value) extends Command {
            public function __construct(
                private bool|string $requiredValue,
                private mixed $value,
            ) {
                parent::__construct();
            }

            public function handle(): void
            {
                $this->promptUntilValid(fn (): mixed => $this->value, $this->requiredValue, null);
            }
        };

        $status = $this->runCommand(
            $command,
            fn ($components) => $components->expects('error')->with($message),
            runningUnitTests: true,
        );

        $this->assertSame(Command::FAILURE, $status);
    }

    public static function requiredFallbackDataProvider(): array
    {
        return [
            'null' => [true, null, 'Required.'],
            'empty custom message' => ['', '', 'Required.'],
            'zero custom message' => ['0', '', '0'],
            'false value' => [true, false, 'Required.'],
            'empty array' => [true, [], 'Required.'],
        ];
    }

    public function testFalseRequiredFlagAllowsFalseFallbackAnswers(): void
    {
        Prompt::fallbackWhen(true);

        $command = new class extends Command {
            public mixed $answer = null;

            public function handle(): void
            {
                $this->answer = $this->promptUntilValid(fn (): bool => false, false, null);
            }
        };

        $this->runCommand($command, fn (): null => null);

        $this->assertFalse($command->answer);
    }

    public function testAutoCompleteFallbackWithArrayOptions(): void
    {
        Prompt::fallbackWhen(true);

        $answer = $this->runPrompt(
            fn () => autocomplete('Color?', ['Red', 'Blue'], default: 'Red'),
            fn ($components) => $components
                ->expects('askWithCompletion')
                ->with('Color?', ['Red', 'Blue'], 'Red')
                ->andReturn('Blue')
        );

        $this->assertSame('Blue', $answer);
    }

    public function testAutoCompleteFallbackNormalizesClosureCollectionOptions(): void
    {
        Prompt::fallbackWhen(true);

        $answer = $this->runPrompt(
            fn () => autocomplete('File?', fn (string $value) => collect([
                $value . '/Models/User.php',
                $value . '/Http/Kernel.php',
            ])),
            fn ($components) => $components
                ->expects('askWithCompletion')
                ->with('File?', m::on(function ($completion) {
                    $this->assertIsCallable($completion);
                    $this->assertSame(['app/Models/User.php', 'app/Http/Kernel.php'], $completion('app'));

                    return true;
                }), null)
                ->andReturn('app/Models/User.php')
        );

        $this->assertSame('app/Models/User.php', $answer);
    }

    public function testSuggestFallbackNormalizesClosureCollectionOptions(): void
    {
        Prompt::fallbackWhen(true);

        $answer = $this->runPrompt(
            fn () => suggest('Name?', fn (string $value) => collect([
                $value . ' Taylor',
                $value . ' Jess',
            ])),
            fn ($components) => $components
                ->expects('askWithCompletion')
                ->with('Name?', m::on(function ($completion) {
                    $this->assertIsCallable($completion);
                    $this->assertSame(['T Taylor', 'T Jess'], $completion('T'));

                    return true;
                }), null)
                ->andReturn('T Taylor')
        );

        $this->assertSame('T Taylor', $answer);
    }

    #[DataProvider('datatableDataProvider')]
    public function testDataTableFallbackReturnsOriginalKeys(Closure $prompt, array $expectedChoices, string $return, mixed $expectedReturn): void
    {
        Prompt::fallbackWhen(true);

        $answer = $this->runPrompt(
            $prompt,
            fn ($components) => $components
                ->expects('choice')
                ->with('Pick', $expectedChoices)
                ->andReturn($return)
        );

        $this->assertSame($expectedReturn, $answer);
    }

    public static function datatableDataProvider(): array
    {
        return [
            'first list row keeps zero key' => [
                fn () => datatable(
                    label: 'Pick',
                    headers: ['Name', 'Email'],
                    rows: [
                        ['Alice', 'alice@example.com'],
                        ['Bob', 'bob@example.com'],
                    ],
                ),
                [
                    1 => '1: Name: Alice, Email: alice@example.com',
                    2 => '2: Name: Bob, Email: bob@example.com',
                ],
                '1',
                0,
            ],
            'second list row keeps numeric index' => [
                fn () => datatable(
                    label: 'Pick',
                    headers: ['Name', 'Email'],
                    rows: [
                        ['Alice', 'alice@example.com'],
                        ['Bob', 'bob@example.com'],
                    ],
                ),
                [
                    1 => '1: Name: Alice, Email: alice@example.com',
                    2 => '2: Name: Bob, Email: bob@example.com',
                ],
                '2',
                1,
            ],
            'string keyed row keeps string key' => [
                fn () => datatable(
                    label: 'Pick',
                    headers: ['Name', 'Email'],
                    rows: [
                        'a' => ['Alice', 'alice@example.com'],
                        'b' => ['Bob', 'bob@example.com'],
                    ],
                ),
                [
                    1 => '1: Name: Alice, Email: alice@example.com',
                    2 => '2: Name: Bob, Email: bob@example.com',
                ],
                '2',
                'b',
            ],
            'integer keyed row keeps integer key' => [
                fn () => datatable(
                    label: 'Pick',
                    headers: ['Name', 'Email'],
                    rows: [
                        10 => ['Alice', 'alice@example.com'],
                        20 => ['Bob', 'bob@example.com'],
                    ],
                ),
                [
                    1 => '1: Name: Alice, Email: alice@example.com',
                    2 => '2: Name: Bob, Email: bob@example.com',
                ],
                '2',
                20,
            ],
            'rows without headers use cell labels only' => [
                fn () => datatable(
                    label: 'Pick',
                    rows: [
                        'a' => ['Alice', 'Developer'],
                        'b' => ['Bob', 'Designer'],
                    ],
                ),
                [
                    1 => '1: Alice, Developer',
                    2 => '2: Bob, Designer',
                ],
                '1',
                'a',
            ],
        ];
    }

    public function testNestedCommandRestoresTheParentPromptConfiguration(): void
    {
        $parent = new ConfiguresPromptsNestedParentCommand;

        Artisan::registerCommand($parent);

        $this->artisan($parent->getName())->assertSuccessful();

        $this->assertSame(ConfiguresPromptsNestedParentCommand::class, CoroutineContext::get('__test.console.prompt_owner'));
    }

    public function testFailingNestedCommandRestoresTheParentPromptConfiguration(): void
    {
        $parent = new ConfiguresPromptsNestedParentCommand(throwFromChild: true);

        Artisan::registerCommand($parent);

        $this->artisan($parent->getName())->assertSuccessful();

        $this->assertSame(ConfiguresPromptsNestedParentCommand::class, CoroutineContext::get('__test.console.prompt_owner'));
    }

    public function testSilentNestedCommandRestoresTheParentPromptConfiguration(): void
    {
        $parent = new ConfiguresPromptsNestedParentCommand(silent: true);

        Artisan::registerCommand($parent);

        $this->artisan($parent->getName())->assertSuccessful();

        $this->assertSame(ConfiguresPromptsNestedParentCommand::class, CoroutineContext::get('__test.console.prompt_owner'));
    }

    protected function runPrompt(Closure $prompt, Closure $expectations, bool $runningUnitTests = false): mixed
    {
        $command = $this->makePromptCommand($prompt);

        $this->runCommand($command, $expectations, $runningUnitTests);

        return $command->answer;
    }

    protected function makePromptCommand(Closure $prompt): object
    {
        return new class($prompt) extends Command {
            public mixed $answer = null;

            public function __construct(protected mixed $prompt)
            {
                parent::__construct();
            }

            public function handle()
            {
                $this->answer = ($this->prompt)();
            }
        };
    }

    protected function runCommand($command, $expectations, bool $runningUnitTests = false): int
    {
        $application = m::mock(Application::class);
        $command->setHypervel($application);

        $application->shouldReceive('make')->withArgs(fn ($abstract) => $abstract === OutputStyle::class)->andReturn($outputStyle = m::mock(OutputStyle::class));
        $application->shouldReceive('make')->withArgs(fn ($abstract) => $abstract === Factory::class)->andReturn($factory = m::mock(Factory::class));
        $application->shouldReceive('bound')->andReturn(false);
        $application->shouldReceive('runningUnitTests')->andReturn($runningUnitTests);
        $application->shouldReceive('call')->with([$command, 'handle'])->andReturnUsing(fn ($callback) => call_user_func($callback));
        $outputStyle->shouldReceive('newLinesWritten')->andReturn(1);

        $expectations($factory);

        return $command->run(new ArrayInput([]), new NullOutput);
    }
}

class ConfiguresPromptsEmptyIntrinsicTextPrompt extends TextPrompt
{
    /**
     * Validate rules intrinsic to the prompt type.
     */
    public function validateIntrinsic(mixed $value): ?string
    {
        return '';
    }
}

class ConfiguresPromptsNestedParentCommand extends Command
{
    protected ?string $signature = 'test:prompt-parent';

    public function __construct(
        protected bool $throwFromChild = false,
        protected bool $silent = false,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        try {
            $command = $this->throwFromChild
                ? ConfiguresPromptsThrowingNestedCommand::class
                : ConfiguresPromptsNestedCommand::class;

            $this->silent ? $this->callSilent($command) : $this->call($command);
        } catch (RuntimeException) {
        }

        $validation = (new ReflectionMethod(Prompt::class, 'getValidateUsing'))->invoke(null);

        $prompt = new TextPrompt('Test', validate: 'required');

        $validation($prompt, $prompt->value());
    }

    protected function validatePrompt(mixed $value, mixed $rules): ?string
    {
        CoroutineContext::set('__test.console.prompt_owner', static::class);

        return null;
    }
}

class ConfiguresPromptsNestedCommand extends Command
{
    protected ?string $signature = 'test:prompt-child';

    public function handle(): void
    {
    }

    protected function validatePrompt(mixed $value, mixed $rules): ?string
    {
        return null;
    }
}

class ConfiguresPromptsThrowingNestedCommand extends Command
{
    protected ?string $signature = 'test:prompt-child-throwing';

    public function handle(): never
    {
        throw new RuntimeException('Nested command failed.');
    }

    protected function validatePrompt(mixed $value, mixed $rules): ?string
    {
        return null;
    }
}
