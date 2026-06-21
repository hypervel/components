<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console;

use Closure;
use Hypervel\Console\Command;
use Hypervel\Console\OutputStyle;
use Hypervel\Console\View\Components\Factory;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Prompts\Prompt;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

use function Hypervel\Prompts\autocomplete;
use function Hypervel\Prompts\datatable;
use function Hypervel\Prompts\multiselect;
use function Hypervel\Prompts\number;
use function Hypervel\Prompts\select;
use function Hypervel\Prompts\suggest;

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
