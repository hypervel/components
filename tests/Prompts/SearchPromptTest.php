<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Exceptions\NonInteractiveValidationException;
use Hypervel\Prompts\Key;
use Hypervel\Prompts\Prompt;
use Hypervel\Prompts\SearchPrompt;
use PHPUnit\Framework\TestCase;

use function Hypervel\Prompts\search;

/**
 * @backupStaticProperties enabled
 * @internal
 * @coversNothing
 */
class SearchPromptTest extends TestCase
{
    public function testAcceptsCallback(): void
    {
        Prompt::fake(['u', 'e', Key::DOWN, Key::ENTER]);

        $result = search(
            label: 'What is your favorite color?',
            options: fn (string $value) => array_filter(
                [
                    'red' => 'Red',
                    'green' => 'Green',
                    'blue' => 'Blue',
                ],
                fn ($option) => str_contains(strtolower($option), strtolower($value)),
            ),
        );

        $this->assertSame('blue', $result);
    }

    public function testReturnsValueWhenListPassed(): void
    {
        Prompt::fake(['u', 'e', Key::DOWN, Key::ENTER]);

        $result = search(
            label: 'What is your favorite color?',
            options: fn (string $value) => array_values(array_filter(
                [
                    'Red',
                    'Green',
                    'Blue',
                ],
                fn ($option) => str_contains(strtolower($option), strtolower($value)),
            )),
        );

        $this->assertSame('Blue', $result);
    }

    public function testSupportsHomeKeyWhileNavigatingOptions(): void
    {
        Prompt::fake(['r', Key::DOWN, Key::DOWN, Key::HOME[0], Key::ENTER]);

        $result = search(
            label: 'What is your favorite color?',
            options: fn (string $value) => array_filter(
                [
                    'Red',
                    'Green',
                    'Blue',
                ],
                fn ($option) => str_contains(strtolower($option), strtolower($value)),
            ),
        );

        $this->assertSame('Red', $result);
    }

    public function testSupportsEndKeyWhileNavigatingOptions(): void
    {
        Prompt::fake(['r', Key::DOWN, Key::END[0], Key::ENTER]);

        $result = search(
            label: 'What is your favorite color?',
            options: fn (string $value) => array_filter(
                [
                    'Red',
                    'Green',
                    'Blue',
                ],
                fn ($option) => str_contains(strtolower($option), strtolower($value)),
            ),
        );

        $this->assertSame('Green', $result);
    }

    public function testReturnsKeyWhenAssociativeArrayPassed(): void
    {
        Prompt::fake(['u', 'e', Key::DOWN, Key::ENTER]);

        $result = search(
            label: 'What is your favorite color?',
            options: fn (string $value) => array_filter(
                [
                    1 => 'Red',
                    2 => 'Green',
                    3 => 'Blue',
                ],
                fn ($option) => str_contains(strtolower($option), strtolower($value)),
            ),
        );

        $this->assertSame(3, $result);
    }

    public function testTransformsValues(): void
    {
        Prompt::fake(['u', 'e', Key::DOWN, Key::ENTER]);

        $result = search(
            label: 'What is your favorite color?',
            options: fn (string $value) => array_filter(
                [
                    'red' => 'Red',
                    'green' => 'Green',
                    'blue' => 'Blue',
                ],
                fn ($option) => str_contains(strtolower($option), $value),
            ),
            transform: fn ($value) => strtoupper($value),
        );

        $this->assertSame('BLUE', $result);
    }

    public function testValidates(): void
    {
        Prompt::fake([Key::DOWN, Key::ENTER, Key::DOWN, Key::ENTER]);

        $result = search(
            label: 'What is your favorite color?',
            options: fn () => [
                'red' => 'Red',
                'green' => 'Green',
                'blue' => 'Blue',
            ],
            validate: fn ($value) => $value === 'red' ? 'Please choose green.' : null
        );

        $this->assertSame('green', $result);
        Prompt::assertOutputContains('Please choose green.');
    }

    public function testCanFallBack(): void
    {
        Prompt::fallbackWhen(true);

        SearchPrompt::fallbackUsing(function (SearchPrompt $prompt) {
            $this->assertSame('What is your favorite color?', $prompt->label);
            return 'result';
        });

        $result = search(
            label: 'What is your favorite color?',
            options: fn () => [
                'red' => 'Red',
                'green' => 'Green',
                'blue' => 'Blue',
            ],
        );

        $this->assertSame('result', $result);
    }

    public function testSupportsEmacsStyleKeyBinding(): void
    {
        Prompt::fake(['u', 'e', Key::CTRL_N, Key::ENTER]);

        $result = search(
            label: 'What is your favorite color?',
            options: fn (string $value) => array_filter(
                [
                    'red' => 'Red',
                    'green' => 'Green',
                    'blue' => 'Blue',
                ],
                fn ($option) => str_contains(strtolower($option), strtolower($value)),
            ),
        );

        $this->assertSame('blue', $result);
    }

    public function testFailsWhenNonInteractive(): void
    {
        $this->expectException(NonInteractiveValidationException::class);
        $this->expectExceptionMessage('Required.');

        Prompt::interactive(false);
        search('What is your favorite color?', fn () => []);
    }

    public function testAllowsRequiredValidationMessageCustomization(): void
    {
        $this->expectException(NonInteractiveValidationException::class);
        $this->expectExceptionMessage('The color is required.');

        Prompt::interactive(false);
        search('What is your favorite color?', fn () => [], required: 'The color is required.');
    }

    public function testSupportsCustomValidation(): void
    {
        Prompt::fake([Key::DOWN, Key::ENTER, Key::DOWN, Key::ENTER]);

        Prompt::validateUsing(function (Prompt $prompt) {
            $this->assertSame('What is your favorite color?', $prompt->label);
            $this->assertSame('in:green', $prompt->validate);
            return $prompt->validate === 'in:green' && $prompt->value() != 'green' ? 'Please choose green.' : null;
        });

        $result = search(
            label: 'What is your favorite color?',
            options: fn () => [
                'red' => 'Red',
                'green' => 'Green',
                'blue' => 'Blue',
            ],
            validate: 'in:green',
        );

        $this->assertSame('green', $result);
        Prompt::assertOutputContains('Please choose green.');

        Prompt::validateUsing(fn () => null);
    }
}
