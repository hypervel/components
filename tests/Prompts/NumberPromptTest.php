<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Exception;
use Hypervel\Prompts\Exceptions\NonInteractiveValidationException;
use Hypervel\Prompts\Key;
use Hypervel\Prompts\NumberPrompt;
use Hypervel\Prompts\Prompt;
use Hypervel\Tests\TestCase;

use function Hypervel\Prompts\number;

/**
 * @internal
 * @coversNothing
 */
class NumberPromptTest extends TestCase
{
    public function testReturnsTheInput()
    {
        Prompt::fake(['1', '0', Key::ENTER]);

        $result = number(label: 'How many items do you want to buy?');

        $this->assertSame(10, $result);
    }

    public function testAcceptsDefaultValue()
    {
        Prompt::fake([Key::ENTER]);

        $result = number(
            label: 'How many items do you want to buy?',
            default: '10'
        );

        $this->assertSame(10, $result);
    }

    public function testValidates()
    {
        Prompt::fake(['n', 'o', Key::ENTER, Key::BACKSPACE, Key::BACKSPACE, '1', '0', Key::ENTER]);

        $result = number(
            label: 'How many items do you want to buy?',
        );

        $this->assertSame(10, $result);

        Prompt::assertOutputContains('Must be a number');
    }

    public function testValidatesMinimumValue()
    {
        Prompt::fake(['0', Key::ENTER, Key::BACKSPACE, '1', Key::ENTER]);

        $result = number(
            label: 'How many items do you want to buy?',
            min: 1,
        );

        $this->assertSame(1, $result);

        Prompt::assertOutputContains('Must be at least 1');
    }

    public function testValidatesMaximumValue()
    {
        Prompt::fake([
            '1', '0', '0',
            Key::ENTER,
            Key::BACKSPACE, Key::BACKSPACE, Key::BACKSPACE,
            '9', '9',
            Key::ENTER,
        ]);

        $result = number(
            label: 'How many items do you want to buy?',
            max: 99,
        );

        $this->assertSame(99, $result);

        Prompt::assertOutputContains('Must be less than 99');
    }

    public function testFallsThroughToOriginalValidation()
    {
        Prompt::fake([
            '1', '0', '0',
            Key::ENTER,
            Key::BACKSPACE, Key::BACKSPACE, Key::BACKSPACE,
            '9', '8',
            Key::ENTER,
            Key::BACKSPACE,
            '9',
            Key::ENTER,
        ]);

        $result = number(
            label: 'How many items do you want to buy?',
            max: 99,
            validate: fn ($value) => $value !== 99 ? 'Must be 99' : null,
        );

        $this->assertSame(99, $result);

        Prompt::assertOutputContains('Must be less than 99');
        Prompt::assertOutputContains('Must be 99');
    }

    public function testFallsThroughToOriginalValidationWithValidateUsing()
    {
        Prompt::validateUsing(function (Prompt $prompt) {
            return $prompt->value() !== 99 ? 'Must be 99' : null;
        });

        Prompt::fake([
            '9', '8',
            Key::ENTER,
            Key::BACKSPACE,
            '9',
            Key::ENTER,
        ]);

        $result = number(
            label: 'How many items do you want to buy?',
            max: 99,
            validate: 'required|int|min:99',
        );

        $this->assertSame(99, $result);

        Prompt::assertOutputContains('Must be 99');

        Prompt::validateUsing(fn () => null);
    }

    public function testStartsWithMinimumValueWhenUpArrowPressedAndValueIsEmpty()
    {
        Prompt::fake([Key::UP, Key::ENTER]);

        $result = number(
            label: 'How many items do you want to buy?',
            min: 1,
            max: 10,
        );

        $this->assertSame(1, $result);
    }

    public function testIncreasesWhenUpArrowPressed()
    {
        Prompt::fake(['1', Key::UP, Key::UP, Key::ENTER]);

        $result = number(
            label: 'How many items do you want to buy?',
            min: 1,
            max: 10,
        );

        $this->assertSame(3, $result);
    }

    public function testWillNotIncreasePastMaximumValue()
    {
        Prompt::fake(['9', Key::UP, Key::UP, Key::ENTER]);

        $result = number(
            label: 'How many items do you want to buy?',
            min: 1,
            max: 10,
        );

        $this->assertSame(10, $result);
    }

    public function testStartsWithMaximumValueWhenDownArrowPressedAndValueIsEmpty()
    {
        Prompt::fake([Key::DOWN, Key::ENTER]);

        $result = number(
            label: 'How many items do you want to buy?',
            min: 1,
            max: 10,
        );

        $this->assertSame(10, $result);
    }

    public function testDecreasesWhenDownArrowPressed()
    {
        Prompt::fake(['3', Key::DOWN, Key::DOWN, Key::ENTER]);

        $result = number(
            label: 'How many items do you want to buy?',
            min: 1,
            max: 10,
        );

        $this->assertSame(1, $result);
    }

    public function testWillNotDecreasePastMinimumValue()
    {
        Prompt::fake(['1', Key::DOWN, Key::DOWN, Key::ENTER]);

        $result = number(
            label: 'How many items do you want to buy?',
            min: 1,
            max: 10,
        );

        $this->assertSame(1, $result);
    }

    public function testCanSetStepSize()
    {
        Prompt::fake(['1', Key::UP, Key::UP, Key::ENTER]);

        $result = number(
            label: 'How many items do you want to buy?',
            step: 2,
        );

        $this->assertSame(5, $result);
    }

    public function testCancels()
    {
        Prompt::fake([Key::CTRL_C]);

        number(label: 'How many items do you want to buy?');

        Prompt::assertOutputContains('Cancelled.');
    }

    public function testBackspaceKeyRemovesCharacter()
    {
        Prompt::fake(['1', '0', 's', Key::BACKSPACE, Key::ENTER]);

        $result = number(label: 'How many items do you want to buy?');

        $this->assertSame(10, $result);
    }

    public function testDeleteKeyRemovesCharacter()
    {
        Prompt::fake(['1', '0', 's', Key::LEFT, Key::DELETE, Key::ENTER]);

        $result = number(label: 'How many items do you want to buy?');

        $this->assertSame(10, $result);
    }

    public function testCanFallBack()
    {
        Prompt::fallbackWhen(true);

        NumberPrompt::fallbackUsing(function (NumberPrompt $prompt) {
            $this->assertSame('How many items do you want to buy?', $prompt->label);

            return 'result';
        });

        $result = number(label: 'How many items do you want to buy?');

        $this->assertSame('result', $result);
    }

    public function testSupportsEmacsStyleKeyBinding()
    {
        Prompt::fake(['1', 's', '0', Key::CTRL_B, Key::CTRL_H, Key::CTRL_F, Key::ENTER]);

        $result = number(label: 'How many items do you want to buy?');

        $this->assertSame(10, $result);
    }

    public function testReturnsEmptyStringWhenNonInteractive()
    {
        Prompt::interactive(false);

        $result = number(label: 'How many items do you want to buy?');

        $this->assertSame('', $result);
    }

    public function testReturnsDefaultValueWhenNonInteractive()
    {
        Prompt::interactive(false);

        $result = number(label: 'How many items do you want to buy?', default: '10');

        $this->assertSame(10, $result);
    }

    public function testValidatesDefaultValueWhenNonInteractive()
    {
        $this->expectException(NonInteractiveValidationException::class);
        $this->expectExceptionMessage('Required.');

        Prompt::interactive(false);

        number(label: 'How many items do you want to buy?', required: true);
    }

    public function testAllowsCustomizingCancellation()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Cancelled.');

        Prompt::cancelUsing(fn () => throw new Exception('Cancelled.'));
        Prompt::fake([Key::CTRL_C]);

        number(label: 'How many items do you want to buy?');
    }
}
