<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\AutoCompletePrompt;
use Hypervel\Prompts\Exceptions\NonInteractiveValidationException;
use Hypervel\Prompts\Key;
use Hypervel\Prompts\Prompt;
use Hypervel\Tests\TestCase;

use function Hypervel\Prompts\autocomplete;

/**
 * @internal
 * @coversNothing
 */
class AutoCompletePromptTest extends TestCase
{
    public function testAcceptsAnyInput()
    {
        Prompt::fake(['B', 'l', 'a', 'c', 'k', Key::ENTER]);

        $result = autocomplete('What is your favorite color?', [
            'Red',
            'Green',
            'Blue',
        ]);

        $this->assertSame('Black', $result);
    }

    public function testCompletesInputUsingTabKey()
    {
        Prompt::fake(['B', 'l', Key::TAB, Key::ENTER]);

        $result = autocomplete('What is your favorite color?', [
            'Red',
            'Green',
            'Blue',
        ]);

        $this->assertSame('Blue', $result);
    }

    public function testCompletesInputUsingRightArrowKey()
    {
        Prompt::fake(['B', 'l', Key::RIGHT_ARROW, Key::ENTER]);

        $result = autocomplete('What is your favorite color?', [
            'Red',
            'Green',
            'Blue',
        ]);

        $this->assertSame('Blue', $result);
    }

    public function testCyclesThroughSuggestionsWithArrowKeys()
    {
        Prompt::fake(['B', Key::DOWN, Key::TAB, Key::ENTER]);

        $result = autocomplete('What is your favorite color?', [
            'Red',
            'Blue',
            'Black',
        ]);

        $this->assertSame('Black', $result);
    }

    public function testCyclesThroughSuggestionsWrappingAround()
    {
        Prompt::fake(['B', Key::UP, Key::TAB, Key::ENTER]);

        $result = autocomplete('What is your favorite color?', [
            'Red',
            'Blue',
            'Black',
        ]);

        // UP from 0 wraps to last match (Black)
        $this->assertSame('Black', $result);
    }

    public function testAllowsEditingAfterAcceptingSuggestion()
    {
        Prompt::fake(['B', 'l', Key::TAB, Key::BACKSPACE, Key::BACKSPACE, 'a', 'c', 'k', Key::ENTER]);

        $result = autocomplete('What is your favorite color?', [
            'Red',
            'Green',
            'Blue',
            'Black',
        ]);

        $this->assertSame('Black', $result);
    }

    public function testAcceptsClosureForOptions()
    {
        Prompt::fake(['a', 'p', 'p', '/', Key::TAB, Key::ENTER]);

        $result = autocomplete(
            label: 'Which file?',
            options: fn (string $value) => array_values(array_filter(
                ['app/Models/User.php', 'config/app.php'],
                fn ($file) => str_starts_with(strtolower($file), strtolower($value)),
            )),
        );

        $this->assertSame('app/Models/User.php', $result);
    }

    public function testResetsHighlightedIndexWhenTyping()
    {
        Prompt::fake(['B', Key::DOWN, 'l', Key::TAB, Key::ENTER]);

        $result = autocomplete('What is your favorite color?', [
            'Red',
            'Blue',
            'Black',
            'Blurple',
        ]);

        // After DOWN, highlighted is on Black. Typing 'l' resets to 0, so TAB picks Blue.
        $this->assertSame('Blue', $result);
    }

    public function testTabRequestsSuggestionsWhenNoGhostTextShowing()
    {
        Prompt::fake(['B', 'l', 'u', 'e', Key::TAB, Key::ENTER]);

        $result = autocomplete('What is your favorite color?', [
            'Blue',
        ]);

        // Typed "Blue" exactly matches the option, no ghost text. TAB refreshes (no-op), enter submits.
        $this->assertSame('Blue', $result);
    }

    public function testTransformsValues()
    {
        Prompt::fake(['B', 'l', Key::TAB, Key::ENTER]);

        $result = autocomplete(
            label: 'What is your favorite color?',
            options: ['Blue'],
            transform: fn ($value) => strtoupper($value),
        );

        $this->assertSame('BLUE', $result);
    }

    public function testValidates()
    {
        Prompt::fake([Key::ENTER, 'X', Key::ENTER]);

        $result = autocomplete(
            label: 'What is your name?',
            options: ['Taylor'],
            validate: fn ($value) => empty($value) ? 'Please enter your name.' : null,
        );

        $this->assertSame('X', $result);

        Prompt::assertOutputContains('Please enter your name.');
    }

    public function testCanFallBack()
    {
        Prompt::fallbackWhen(true);

        AutoCompletePrompt::fallbackUsing(function (AutoCompletePrompt $prompt) {
            $this->assertSame('What is your favorite color?', $prompt->label);

            return 'result';
        });

        $result = autocomplete('What is your favorite color?', [
            'Red',
            'Green',
            'Blue',
        ]);

        $this->assertSame('result', $result);
    }

    public function testReturnsEmptyStringWhenNonInteractive()
    {
        Prompt::interactive(false);

        $result = autocomplete('What is your favorite color?', [
            'Red',
            'Green',
            'Blue',
        ]);

        $this->assertSame('', $result);
    }

    public function testReturnsDefaultValueWhenNonInteractive()
    {
        Prompt::interactive(false);

        $result = autocomplete('What is your favorite color?', [
            'Red',
            'Green',
            'Blue',
        ], default: 'Yellow');

        $this->assertSame('Yellow', $result);
    }

    public function testValidatesDefaultValueWhenNonInteractive()
    {
        $this->expectException(NonInteractiveValidationException::class);
        $this->expectExceptionMessage('Required.');

        Prompt::interactive(false);

        autocomplete('What is your favorite color?', [
            'Red',
            'Green',
            'Blue',
        ], required: true);
    }

    public function testAcceptsCollection()
    {
        Prompt::fake(['B', 'l', Key::TAB, Key::ENTER]);

        $result = autocomplete('What is your favorite color?', collect([
            'Red',
            'Green',
            'Blue',
        ]));

        $this->assertSame('Blue', $result);
    }

    public function testSupportsCustomValidation()
    {
        Prompt::validateUsing(function (Prompt $prompt) {
            $this->assertSame('What is your name?', $prompt->label);
            $this->assertSame('min:2', $prompt->validate);

            return $prompt->validate === 'min:2' && strlen($prompt->value()) < 2 ? 'Minimum 2 chars!' : null;
        });

        Prompt::fake(['A', Key::ENTER, 'n', 'd', 'r', 'e', 'a', Key::ENTER]);

        $result = autocomplete(
            label: 'What is your name?',
            options: ['Jess', 'Taylor'],
            validate: 'min:2',
        );

        $this->assertSame('Andrea', $result);

        Prompt::assertOutputContains('Minimum 2 chars!');

        Prompt::validateUsing(fn () => null);
    }
}
