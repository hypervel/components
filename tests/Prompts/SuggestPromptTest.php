<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Exceptions\NonInteractiveValidationException;
use Hypervel\Prompts\Key;
use Hypervel\Prompts\Prompt;
use Hypervel\Prompts\SuggestPrompt;
use Hypervel\Tests\TestCase;

use function Hypervel\Prompts\suggest;

/**
 * @internal
 * @coversNothing
 */
class SuggestPromptTest extends TestCase
{
    public function testAcceptsAnyInput(): void
    {
        Prompt::fake(['B', 'l', 'a', 'c', 'k', Key::ENTER]);

        $result = suggest('What is your favorite color?', [
            'Red',
            'Green',
            'Blue',
        ]);

        $this->assertSame('Black', $result);
    }

    public function testCompletesInputUsingTabKey(): void
    {
        Prompt::fake(['b', Key::TAB, Key::ENTER]);

        $result = suggest('What is your favorite color?', [
            'Red',
            'Green',
            'Blue',
        ]);

        $this->assertSame('Blue', $result);
    }

    public function testCompletesInputUsingArrowKeys(): void
    {
        Prompt::fake(['b', Key::DOWN, Key::DOWN, Key::DOWN, Key::UP, Key::ENTER]);

        $result = suggest('What is your favorite color?', [
            'Red',
            'Blue',
            'Black',
            'Blurple',
        ]);

        $this->assertSame('Black', $result);
    }

    public function testAcceptsCallback(): void
    {
        Prompt::fake(['e', 'e', Key::DOWN, Key::ENTER]);

        $result = suggest(
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

    public function testAcceptsCollection(): void
    {
        Prompt::fake(['b', Key::TAB, Key::ENTER]);

        $result = suggest('What is your favorite color?', collect([
            'Red',
            'Green',
            'Blue',
        ]));

        $this->assertSame('Blue', $result);
    }

    public function testTransformsValues(): void
    {
        Prompt::fake([Key::SPACE, 'J', 'e', 's', 's', Key::TAB, Key::ENTER]);

        $result = suggest(
            label: 'What is your name?',
            options: ['Jess'],
            transform: fn ($value) => trim($value),
        );

        $this->assertSame('Jess', $result);
    }

    public function testValidates(): void
    {
        Prompt::fake([Key::ENTER, 'X', Key::ENTER]);

        $result = suggest(
            label: 'What is your name?',
            options: ['Taylor'],
            validate: fn ($value) => empty($value) ? 'Please enter your name.' : null,
        );

        $this->assertSame('X', $result);
        Prompt::assertOutputContains('Please enter your name.');
    }

    public function testCanFallBack(): void
    {
        Prompt::fallbackWhen(true);

        SuggestPrompt::fallbackUsing(function (SuggestPrompt $prompt) {
            $this->assertSame('What is your favorite color?', $prompt->label);
            return 'result';
        });

        $result = suggest('What is your favorite color?', [
            'Red',
            'Green',
            'Blue',
        ]);

        $this->assertSame('result', $result);

        Prompt::fallbackWhen(false);
    }

    public function testValidatesDefaultValueWhenNonInteractive(): void
    {
        $this->expectException(NonInteractiveValidationException::class);
        $this->expectExceptionMessage('Required.');

        Prompt::interactive(false);
        suggest('What is your favorite color?', [
            'Red',
            'Green',
            'Blue',
        ], required: true);
    }

    public function testSupportsCustomValidation(): void
    {
        Prompt::validateUsing(function (Prompt $prompt) {
            $this->assertSame('What is your name?', $prompt->label);
            $this->assertSame('min:2', $prompt->validate);
            return $prompt->validate === 'min:2' && strlen($prompt->value()) < 2 ? 'Minimum 2 chars!' : null;
        });

        Prompt::fake(['A', Key::ENTER, 'n', 'd', 'r', 'e', 'a', Key::ENTER]);

        $result = suggest(
            label: 'What is your name?',
            options: ['Jess', 'Taylor'],
            validate: 'min:2',
        );

        $this->assertSame('Andrea', $result);
        Prompt::assertOutputContains('Minimum 2 chars!');

        Prompt::validateUsing(fn () => null);
    }

    public function testSupportsHomeKeyWhileNavigatingOptions()
    {
        Prompt::fake([Key::DOWN, Key::DOWN, Key::HOME[0], Key::ENTER]);

        $result = suggest('What is your favorite color?', [
            'Red',
            'Blue',
            'Green',
        ]);

        $this->assertSame('Red', $result);
    }

    public function testSupportsEndKeyWhileNavigatingOptions()
    {
        Prompt::fake([Key::DOWN, Key::END[0], Key::ENTER]);

        $result = suggest('What is your favorite color?', [
            'Red',
            'Blue',
            'Green',
        ]);

        $this->assertSame('Green', $result);
    }

    public function testAcceptsCallbackReturningCollection()
    {
        Prompt::fake(['b', Key::TAB, Key::ENTER]);

        $result = suggest(
            label: 'What is your favorite color?',
            options: fn ($value) => collect([
                'Red',
                'Green',
                'Blue',
            ])->filter(
                fn ($name) => str_contains(
                    strtoupper($name),
                    strtoupper($value)
                )
            )
        );

        $this->assertSame('Blue', $result);
    }

    public function testSupportsEmacsStyleKeyBinding()
    {
        Prompt::fake(['b', Key::CTRL_N, Key::CTRL_N, Key::CTRL_N, Key::CTRL_P, Key::ENTER]);

        $result = suggest('What is your favorite color?', [
            'Red',
            'Blue',
            'Black',
            'Blurple',
        ]);

        $this->assertSame('Black', $result);
    }

    public function testReturnsEmptyStringWhenNonInteractive()
    {
        Prompt::interactive(false);

        $result = suggest('What is your favorite color?', [
            'Red',
            'Green',
            'Blue',
        ]);

        $this->assertSame('', $result);
    }

    public function testReturnsDefaultValueWhenNonInteractive()
    {
        Prompt::interactive(false);

        $result = suggest('What is your favorite color?', [
            'Red',
            'Green',
            'Blue',
        ], default: 'Yellow');

        $this->assertSame('Yellow', $result);
    }
}
