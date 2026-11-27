<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Hypervel\Console\Command;
use Hypervel\Console\OutputStyle;
use Hypervel\Console\View\Components\Factory;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Foundation\Application as FoundationApplication;
use Hypervel\Prompts\Exceptions\NonInteractiveValidationException;
use Hypervel\Prompts\PausePrompt;
use Hypervel\Prompts\Prompt;
use Hypervel\Prompts\TextPrompt;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Console\Fixtures\ComponentsFactory;
use InvalidArgumentException;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;

use function Hypervel\Prompts\autocomplete;
use function Hypervel\Prompts\confirm;
use function Hypervel\Prompts\datatable;
use function Hypervel\Prompts\datepicker;
use function Hypervel\Prompts\datetimepicker;
use function Hypervel\Prompts\multisearch;
use function Hypervel\Prompts\multiselect;
use function Hypervel\Prompts\number;
use function Hypervel\Prompts\password;
use function Hypervel\Prompts\search;
use function Hypervel\Prompts\select;
use function Hypervel\Prompts\suggest;
use function Hypervel\Prompts\text;
use function Hypervel\Prompts\textarea;
use function Hypervel\Prompts\timepicker;

class ConfiguresPromptsTest extends TestCase
{
    use VerifiesDoubles;

    #[DataProvider('selectDataProvider')]
    public function testSelectFallback(Closure $prompt, string $answer, string|int $expectedReturn): void
    {
        $this->assertSame($expectedReturn, $this->answer($prompt, $answer));
    }

    public static function selectDataProvider(): array
    {
        return [
            'list with no default' => [fn (): string|int => select('Test', ['a', 'b', 'c']), 'b', 'b'],
            'numeric keys with no default' => [fn (): string|int => select('Test', [1 => 'a', 2 => 'b', 3 => 'c']), '2', 2],
            'assoc with no default' => [fn (): string|int => select('Test', ['a' => 'A', 'b' => 'B', 'c' => 'C']), 'b', 'b'],
            'list with default' => [fn (): string|int => select('Test', ['a', 'b', 'c'], 'b'), 'b', 'b'],
            'numeric keys with default' => [fn (): string|int => select('Test', [1 => 'a', 2 => 'b', 3 => 'c'], 2), '2', 2],
            'assoc with default' => [fn (): string|int => select('Test', ['a' => 'A', 'b' => 'B', 'c' => 'C'], 'b'), 'b', 'b'],
        ];
    }

    #[DataProvider('multiselectDataProvider')]
    public function testMultiselectFallback(Closure $prompt, string $answer, array $expectedReturn): void
    {
        $this->assertSame($expectedReturn, $this->answer($prompt, $answer));
    }

    public static function multiselectDataProvider(): array
    {
        return [
            'list with no default' => [fn (): array => multiselect('Test', ['a', 'b', 'c']), '', []],
            'numeric keys with no default' => [fn (): array => multiselect('Test', [1 => 'a', 2 => 'b', 3 => 'c']), '', []],
            'assoc with no default' => [fn (): array => multiselect('Test', ['a' => 'A', 'b' => 'B', 'c' => 'C']), '', []],
            'list with default' => [fn (): array => multiselect('Test', ['a', 'b', 'c'], ['b', 'c']), 'b,c', ['b', 'c']],
            'numeric keys with default' => [fn (): array => multiselect('Test', [1 => 'a', 2 => 'b', 3 => 'c'], [2, 3]), '2,3', [2, 3]],
            'assoc with default' => [fn (): array => multiselect('Test', ['a' => 'A', 'b' => 'B', 'c' => 'C'], ['b', 'c']), 'b,c', ['b', 'c']],
            'required list with no default' => [fn (): array => multiselect('Test', ['a', 'b', 'c'], required: true), 'b,c', ['b', 'c']],
            'required numeric keys with no default' => [fn (): array => multiselect('Test', [1 => 'a', 2 => 'b', 3 => 'c'], required: true), '2,3', [2, 3]],
            'required assoc with no default' => [fn (): array => multiselect('Test', ['a' => 'A', 'b' => 'B', 'c' => 'C'], required: true), 'b,c', ['b', 'c']],
            'required list with default' => [fn (): array => multiselect('Test', ['a', 'b', 'c'], ['b', 'c'], required: true), 'b,c', ['b', 'c']],
            'required numeric keys with default' => [fn (): array => multiselect('Test', [1 => 'a', 2 => 'b', 3 => 'c'], [2, 3], required: true), '2,3', [2, 3]],
            'required assoc with default' => [fn (): array => multiselect('Test', ['a' => 'A', 'b' => 'B', 'c' => 'C'], ['b', 'c'], required: true), 'b,c', ['b', 'c']],
        ];
    }

    #[TestWith([5, '12', 12])]
    #[TestWith([0, '', 0])]
    public function testNumberFallback(int $default, string $answer, int $expected): void
    {
        $this->assertSame($expected, $this->answer(
            fn (): int => number('How many?', default: $default),
            $answer,
        ));
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
                    ->returns('0');
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
                fn ($components) => $components->expects('ask')->with('Test', null)->returns('value'),
                'value!',
            ],
            'textarea' => [
                fn () => textarea('Test', transform: fn (string $value): string => $value . '!'),
                fn ($components) => $components->expects('ask')->with('Test', null, true)->returns('value'),
                'value!',
            ],
            'number' => [
                fn () => number('Test', transform: fn (int $value): int => $value + 1),
                fn ($components) => $components->expects('ask')->with('Test', null)->returns('1'),
                2,
            ],
            'password' => [
                fn () => password('Test', transform: fn (string $value): string => $value . '!'),
                fn ($components) => $components->expects('secret')->with('Test')->returns('value'),
                'value!',
            ],
            'pause' => [
                function (): bool {
                    $prompt = new PausePrompt('Test');
                    $prompt->transform = fn (bool $value): bool => ! $value;

                    return $prompt->prompt();
                },
                fn ($components) => $components->expects('ask')->with('Test')->returns(null),
                true,
            ],
            'confirm' => [
                fn () => confirm('Test', transform: fn (bool $value): bool => ! $value),
                fn ($components) => $components->expects('confirm')->with('Test', true)->returns(true),
                false,
            ],
            'select' => [
                fn () => select('Test', ['a'], transform: fn (string $value): string => $value . '!'),
                fn ($components) => $components->expects('choice')->with('Test', ['a'], null)->returns('a'),
                'a!',
            ],
            'multiselect' => [
                fn () => multiselect('Test', ['a'], transform: fn (array $values): array => [...$values, 'b']),
                fn ($components) => $components->expects('choice')->with('Test', ['None', 'a'], 'None', null, true)->returns(['a']),
                ['a', 'b'],
            ],
            'datatable' => [
                fn () => datatable(['Name'], [['Taylor']], label: 'Test', transform: fn (int $value): string => "row-{$value}"),
                fn ($components) => $components->expects('choice')->with('Test', [1 => '1: Name: Taylor'])->returns('1'),
                'row-0',
            ],
            'suggest' => [
                fn () => suggest('Test', ['value'], transform: fn (string $value): string => $value . '!'),
                fn ($components) => $components->expects('askWithCompletion')->with('Test', ['value'], null)->returns('value'),
                'value!',
            ],
            'autocomplete' => [
                fn () => autocomplete('Test', ['value'], transform: fn (string $value): string => $value . '!'),
                fn ($components) => $components->expects('askWithCompletion')->with('Test', ['value'], null)->returns('value'),
                'value!',
            ],
            'search' => [
                fn () => search('Test', fn (): array => ['a'], transform: fn (string $value): string => $value . '!'),
                function ($components): void {
                    $components->expects('ask')->with('Test')->returns('query');
                    $components->expects('choice')->with('Test', ['a'], null)->returns('a');
                },
                'a!',
            ],
            'datepicker' => [
                fn () => datepicker('Test', default: '2026-07-24', transform: fn (DateTimeImmutable $value): string => $value->format('Y-m-d')),
                fn ($components) => $components->expects('ask')->with('Test', '2026-07-24')->returns('2026-07-25'),
                '2026-07-25',
            ],
            'datetimepicker' => [
                fn () => datetimepicker('Test', default: '2026-07-24 14:30', transform: fn (DateTimeImmutable $value): string => $value->format('Y-m-d H:i')),
                fn ($components) => $components->expects('ask')->with('Test', '2026-07-24 14:30')->returns('2026-07-25 09:15'),
                '2026-07-25 09:15',
            ],
            'timepicker' => [
                fn () => timepicker('Test', default: '14:30', transform: fn (DateTimeImmutable $value): string => $value->format('H:i')),
                fn ($components) => $components->expects('ask')->with('Test', '14:30')->returns('09:15'),
                '09:15',
            ],
            'multisearch' => [
                fn () => multisearch('Test', fn (): array => ['a'], transform: fn (array $values): array => [...$values, 'b']),
                function ($components): void {
                    $components->expects('ask')->with('Test')->returns('query');
                    $components->expects('choice')->with('Test', ['None', 'a'], 'None', null, true)->returns(['a']);
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
                fn ($components) => $components->expects('ask')->with('Test', '0')->returns('answer'),
                'answer',
            ],
            'textarea' => [
                fn () => textarea('Test', default: '0'),
                fn ($components) => $components->expects('ask')->with('Test', '0', true)->returns('answer'),
                'answer',
            ],
            'suggest' => [
                fn () => suggest('Test', ['answer'], default: '0'),
                fn ($components) => $components->expects('askWithCompletion')->with('Test', ['answer'], '0')->returns('answer'),
                'answer',
            ],
            'autocomplete' => [
                fn () => autocomplete('Test', ['answer'], default: '0'),
                fn ($components) => $components->expects('askWithCompletion')->with('Test', ['answer'], '0')->returns('answer'),
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
            fn ($components) => $components->expects('ask')->with('Test', null)->returns('value'),
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
                $components->expects('ask')->with('Test', null)->returns('0');
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

    #[DataProvider('invalidFallbackValidatorResultDataProvider')]
    public function testFallbackRejectsInvalidValidatorResults(mixed $result): void
    {
        Prompt::fallbackWhen(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('The validator must return a string or null.');

        $this->runPrompt(
            fn () => text('Test', validate: fn (): mixed => $result),
            fn ($components) => $components->expects('ask')->with('Test', null)->returns('value'),
        );
    }

    public static function invalidFallbackValidatorResultDataProvider(): array
    {
        return [
            'false' => [false],
            'true' => [true],
            'integer' => [0],
            'array' => [['error']],
        ];
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

    public function testPickerFallbackKeepsTheSelectedDateAndTimezone(): void
    {
        Prompt::fallbackWhen(true);

        $result = $this->runPrompt(
            fn () => timepicker('Test', default: new DateTimeImmutable('2026-01-12 14:30', new DateTimeZone('America/New_York'))),
            fn ($components) => $components->expects('ask')->with('Test', '14:30')->returns('10:30'),
        );

        $this->assertSame('2026-01-12 10:30 America/New_York', $result->format('Y-m-d H:i e'));
    }

    public function testPickerFallbackReturnsTheDisplayedSelectionWhenAccepted(): void
    {
        Prompt::fallbackWhen(true);

        $result = $this->runPrompt(
            fn () => datepicker('Test', default: '2026-07-24', min: '2026-08-01'),
            fn ($components) => $components->expects('ask')->with('Test', '2026-08-01')->returns('2026-08-01'),
        );

        $this->assertSame('2026-08-01', $result->format('Y-m-d'));
    }

    #[DataProvider('invalidPickerFallbackAnswerDataProvider')]
    public function testPickerFallbackRejectsInvalidAnswers(Closure $prompt, string $displayed, string $answer, string $error): void
    {
        Prompt::fallbackWhen(true);

        $status = $this->runCommand(
            $this->makePromptCommand($prompt),
            function ($components) use ($displayed, $answer, $error): void {
                $components->expects('ask')->with('Test', $displayed)->returns($answer);
                $components->expects('error')->with($error);
            },
            runningUnitTests: true,
        );

        $this->assertSame(Command::FAILURE, $status);
    }

    public static function invalidPickerFallbackAnswerDataProvider(): array
    {
        return [
            'overflowing date' => [fn () => datepicker('Test', default: '2026-02-01'), '2026-02-01', '2026-02-30', 'Date [2026-02-30] is not valid.'],
            'year zero' => [fn () => datepicker('Test', default: '2026-02-01'), '2026-02-01', '0000-01-01', 'Date [0000-01-01] is not valid.'],
            'null byte' => [fn () => datepicker('Test', default: '2026-02-01'), '2026-02-01', "2026-02-02\0", "Date [2026-02-02\0] is not valid."],
            'outside the range' => [fn () => datepicker('Test', default: '2026-07-24', max: '2026-07-25'), '2026-07-24', '2026-07-30', 'Must be on or before 2026-07-25.'],
            'skipped by daylight saving' => [
                fn () => timepicker('Test', default: new DateTimeImmutable('2026-03-08 01:30', new DateTimeZone('America/New_York'))),
                '01:30',
                '02:30',
                'Time [02:30] is not valid.',
            ],
        ];
    }

    #[DataProvider('nonInteractivePickerFallbackDataProvider')]
    public function testNonInteractivePickerFallbackValidatesTheDefault(Closure $prompt, string $error): void
    {
        Prompt::fallbackWhen(true);

        $this->expectException(NonInteractiveValidationException::class);
        $this->expectExceptionMessage($error);

        $this->runCommand(
            $this->makePromptCommand($prompt),
            fn ($components) => $components->expects('error')->with($error),
            interactive: false,
        );
    }

    public static function nonInteractivePickerFallbackDataProvider(): array
    {
        return [
            'missing required default' => [fn () => datepicker('Test', required: true), 'Required.'],
            'default outside the range' => [fn () => datepicker('Test', default: '2026-07-24', min: '2026-08-01'), 'Must be on or after 2026-08-01.'],
            'default whose clamp target falls in a daylight saving gap' => [fn () => self::gapTimepicker(), 'Must be on or before 02:30.'],
        ];
    }

    public function testNonInteractivePickerFallbackValidatesAnUnrepresentableStartWhileRunningTests(): void
    {
        Prompt::fallbackWhen(true);

        // Tests run prompts interactively, but this fallback follows its non-interactive input.
        $status = $this->runCommand(
            $this->makePromptCommand(fn () => self::gapTimepicker()),
            fn ($components) => $components->expects('error')->with('Must be on or before 02:30.'),
            runningUnitTests: true,
            interactive: false,
        );

        $this->assertSame(Command::FAILURE, $status);
    }

    public function testInteractivePickerFallbackRejectsAnUnrepresentableStartBeforeAsking(): void
    {
        Prompt::fallbackWhen(true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be represented');

        // The strict components double fails if the fallback asks.
        $this->runCommand($this->makePromptCommand(fn () => self::gapTimepicker()), function (): void {});
    }

    public function testNonInteractiveFallbackFailsInsteadOfRetryingTheSameDefault(): void
    {
        Prompt::fallbackWhen(true);

        $command = $this->makePromptCommand(fn () => text('Name?', required: true));

        $this->expectException(NonInteractiveValidationException::class);
        $this->expectExceptionMessage('Required.');

        $this->runCommand(
            $command,
            function ($components): void {
                $components->expects('ask')->with('Name?', null)->returns(null);
                $components->expects('error')->with('Required.');
            },
            interactive: false,
        );
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
                ->returns('Blue')
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
            function ($components) use (&$completion): void {
                $components->expects('askWithCompletion')
                    ->with('File?', Argument::capture($completion), null)
                    ->returns('app/Models/User.php');
            }
        );

        $this->assertSame('app/Models/User.php', $answer);
        $this->assertIsCallable($completion);
        $this->assertSame(['app/Models/User.php', 'app/Http/Kernel.php'], $completion('app'));
    }

    public function testSuggestFallbackNormalizesClosureCollectionOptions(): void
    {
        Prompt::fallbackWhen(true);

        $answer = $this->runPrompt(
            fn () => suggest('Name?', fn (string $value) => collect([
                $value . ' Taylor',
                $value . ' Jess',
            ])),
            function ($components) use (&$completion): void {
                $components->expects('askWithCompletion')
                    ->with('Name?', Argument::capture($completion), null)
                    ->returns('T Taylor');
            }
        );

        $this->assertSame('T Taylor', $answer);
        $this->assertIsCallable($completion);
        $this->assertSame(['T Taylor', 'T Jess'], $completion('T'));
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
                ->returns($return)
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

        $this->artisan($parent->getName())->expectsOutput('Parent hook ran')->assertSuccessful();

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

    /**
     * Run the given prompt behind a real command, feeding it the given typed answer
     * through a real input stream, and return what the prompt resolved to.
     */
    protected function answer(Closure $prompt, string $answer): mixed
    {
        Prompt::fallbackWhen(true);

        $command = $this->makePromptCommand($prompt);
        $command->setHypervel(new FoundationApplication(__DIR__));
        $input = new ArrayInput([]);
        $stream = fopen('php://memory', 'w+');

        try {
            fwrite($stream, $answer . "\n");
            rewind($stream);
            $input->setStream($stream);

            $command->run($input, new BufferedOutput);

            return $command->answer;
        } finally {
            fclose($stream);
        }
    }

    protected function runPrompt(Closure $prompt, Closure $expectations, bool $runningUnitTests = false): mixed
    {
        $command = $this->makePromptCommand($prompt);

        $this->runCommand($command, $expectations, $runningUnitTests);

        return $command->answer;
    }

    /**
     * Ask for a time whose max is a clock time that doesn't exist on the default's date.
     */
    private static function gapTimepicker(): ?DateTimeImmutable
    {
        // Daylight saving skips 02:00 to 03:00 in New York on 2026-03-08.
        return timepicker('Test', default: new DateTimeImmutable('2026-03-08 04:30', new DateTimeZone('America/New_York')), max: '02:30');
    }

    protected function makePromptCommand(Closure $prompt): object
    {
        return new class($prompt) extends Command {
            public mixed $answer = null;

            public function __construct(protected Closure $prompt)
            {
                parent::__construct();
            }

            public function handle(): void
            {
                $this->answer = ($this->prompt)();
            }
        };
    }

    /**
     * Run the command with the expected fallback interactions.
     */
    protected function runCommand(Command $command, Closure $expectations, bool $runningUnitTests = false, bool $interactive = true): int
    {
        $application = Double::for(Application::class, override: true)->strict();
        $command->setHypervel($application->instance());

        $input = new ArrayInput([]);
        $input->setInteractive($interactive);
        $output = new NullOutput;
        $outputStyle = new OutputStyle($input, $output);
        $application->expects('make')->with(OutputStyle::class, Argument::remaining())->returns($outputStyle);
        $factory = Double::for(ComponentsFactory::class)->strict();
        $application->expects('make')->with(Factory::class, Argument::remaining())->returns($factory);
        $application->allows('bound')->returns(false);
        $application->allows('runningUnitTests')->returns($runningUnitTests);
        $application->expects('call')->with(Argument::same([$command, 'handle']))->resolves(fn (callable $callback): mixed => call_user_func($callback));

        $expectations($factory);

        return $command->run($input, $output);
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

    /**
     * Report when the parent command's prompt hook runs.
     */
    protected function restorePrompts(): void
    {
        parent::restorePrompts();

        $this->line('Parent hook ran');
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
