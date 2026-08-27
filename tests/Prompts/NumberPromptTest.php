<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Exception;
use Hypervel\Prompts\Exceptions\NonInteractiveValidationException;
use Hypervel\Prompts\Key;
use Hypervel\Prompts\NumberPrompt;
use Hypervel\Prompts\Prompt;
use Hypervel\Prompts\Support\Utils;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;

use function Hypervel\Prompts\number;

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

    public function testValidates(): void
    {
        Prompt::fake(['n', 'o', Key::ENTER, Key::BACKSPACE, Key::BACKSPACE, '1', '0', Key::ENTER]);

        $result = number(
            label: 'How many items do you want to buy?',
        );

        $this->assertSame(10, $result);

        Prompt::assertOutputContains('Must be an integer');
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

    public function testValidatesMaximumValue(): void
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

        Prompt::assertOutputContains('Must be at most 99');
    }

    public function testFallsThroughToOriginalValidation(): void
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

        Prompt::assertOutputContains('Must be at most 99');
        Prompt::assertOutputContains('Must be 99');
    }

    public function testFallsThroughToOriginalValidationWithValidateUsing(): void
    {
        Prompt::validateUsing(function (Prompt $prompt, mixed $value) {
            $this->assertSame('required|int|min:99', $prompt->validate);

            return $value !== 99 ? 'Must be 99' : null;
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

    #[DataProvider('validIntegerValues')]
    public function testParsesStrictSignedDecimalIntegers(string $value, int $expected): void
    {
        $this->assertSame($expected, NumberPrompt::parseInteger($value));
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function validIntegerValues(): array
    {
        return [
            'zero' => ['0', 0],
            'positive sign' => ['+7', 7],
            'negative sign' => ['-7', -7],
            'leading zeroes' => ['+0007', 7],
            'negative zero' => ['-000', 0],
            'maximum' => [(string) PHP_INT_MAX, PHP_INT_MAX],
            'minimum' => [(string) PHP_INT_MIN, PHP_INT_MIN],
        ];
    }

    #[DataProvider('invalidIntegerValues')]
    public function testRejectsAnythingOutsideTheIntegerGrammarOrRange(string $value): void
    {
        $this->assertNull(NumberPrompt::parseInteger($value));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidIntegerValues(): array
    {
        return [
            'empty' => [''],
            'positive overflow' => [((string) PHP_INT_MAX) . '0'],
            'negative overflow' => [((string) PHP_INT_MIN) . '0'],
            'fraction' => ['1.5'],
            'exponent' => ['1e3'],
            'leading whitespace' => [' 1'],
            'trailing whitespace' => ['1 '],
            'sign only' => ['+'],
            'non-ASCII digits' => ['１２'],
        ];
    }

    #[DataProvider('intrinsicValidationFailures')]
    public function testIntrinsicValidationRunsWithoutExternalRules(
        string $value,
        string $message,
        ?int $min = null,
        ?int $max = null,
    ): void {
        Prompt::interactive(false);

        $this->expectException(NonInteractiveValidationException::class);
        $this->expectExceptionMessage($message);

        number('Value', default: $value, min: $min, max: $max);
    }

    /**
     * @return array<string, array{string, string, ?int, ?int}>
     */
    public static function intrinsicValidationFailures(): array
    {
        return [
            'syntax' => ['1.5', 'Must be an integer', null, null],
            'positive overflow' => [((string) PHP_INT_MAX) . '0', 'Must be at most ' . PHP_INT_MAX, null, null],
            'negative overflow' => [((string) PHP_INT_MIN) . '0', 'Must be at least ' . PHP_INT_MIN, null, null],
            'minimum' => ['0', 'Must be at least 1', 1, null],
            'maximum' => ['10', 'Must be at most 9', null, 9],
        ];
    }

    public function testBoundsAreInclusive(): void
    {
        $prompt = new NumberPrompt('Value', min: -2, max: 2);

        $this->assertNull($prompt->validateIntrinsic(-2));
        $this->assertNull($prompt->validateIntrinsic(2));
    }

    public function testRejectsAnInvertedRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The minimum value must not be greater than the maximum value.');

        new NumberPrompt('Value', min: 2, max: 1);
    }

    public function testTransformRunsOnceBeforeIntrinsicAndCallerValidation(): void
    {
        Prompt::interactive(false);
        $transformCalls = 0;
        $validatedValue = null;

        $result = number(
            label: 'Value',
            default: ' 7 ',
            validate: function (mixed $value) use (&$validatedValue): ?string {
                $validatedValue = $value;

                return null;
            },
            transform: function (string $value) use (&$transformCalls): string {
                ++$transformCalls;

                return trim($value);
            },
        );

        $this->assertSame('7', $result);
        $this->assertSame('7', $validatedValue);
        $this->assertSame(1, $transformCalls);
    }

    public function testAcceptsAnIntegerDefault(): void
    {
        Prompt::interactive(false);

        $this->assertSame(7, number('Value', default: 7));
    }

    public function testNormalizesNonpositiveSteps(): void
    {
        Prompt::fake(['1', Key::UP, Key::ENTER]);

        $this->assertSame(2, number('Value', step: 0));
    }

    public function testArrowArithmeticSaturatesAtIntegerBoundaries(): void
    {
        Prompt::fake([Key::UP, Key::ENTER]);

        $this->assertSame(PHP_INT_MAX, number('Value', default: PHP_INT_MAX, step: PHP_INT_MAX));

        Prompt::fake([Key::DOWN, Key::ENTER]);

        $this->assertSame(PHP_INT_MIN, number('Value', default: PHP_INT_MIN, step: PHP_INT_MAX));
    }

    public function testEmptyArrowSeedsAreClampedIntoTheConfiguredRange(): void
    {
        Prompt::fake([Key::UP, Key::ENTER]);

        $this->assertSame(0, number('Value', max: 0));

        Prompt::fake([Key::DOWN, Key::ENTER]);

        $this->assertSame(1, number('Value', min: 1));
    }

    public function testArrowMutationRecomputesTheCursorFromTheCompleteValue(): void
    {
        $prompt = new NumberPrompt('Value', default: -9, step: 10);

        (new ReflectionMethod($prompt, 'increaseValue'))->invoke($prompt);

        $this->assertSame(1, $prompt->value());
        $this->assertSame(1, (new ReflectionProperty($prompt, 'cursorPosition'))->getValue($prompt));
    }

    public function testCursorRenderingPreservesTheRawTypedInteger(): void
    {
        $prompt = new NumberPrompt('Value', default: '+007');

        $this->assertSame('+007 ', Utils::stripEscapeSequences($prompt->valueWithCursor(20)));
    }

    public function testCancelledZeroIsRenderedInsteadOfThePlaceholder(): void
    {
        Prompt::fake(['0', Key::CTRL_C]);

        number(label: 'Value', placeholder: 'placeholder');

        $output = Prompt::strippedContent();
        $cancelFrame = substr($output, strrpos($output, ' ┌'));

        $this->assertStringContainsString('│ 0 ', $cancelFrame);
        $this->assertStringNotContainsString('placeholder', $cancelFrame);
    }

    public function testNarrowTerminalsDoNotProduceNegativeArrowPadding(): void
    {
        Prompt::fake([Key::ENTER]);
        Prompt::terminal()->shouldReceive('cols')->andReturn(8); // @phpstan-ignore-line

        $this->assertSame(1, number('Value', default: 1));
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
