<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Exceptions\NonInteractiveValidationException;
use Hypervel\Prompts\Key;
use Hypervel\Prompts\Prompt;
use PHPUnit\Framework\TestCase;

use function Hypervel\Prompts\select;

/**
 * @backupStaticProperties enabled
 * @internal
 * @coversNothing
 */
class SelectPromptTest extends TestCase
{
    public function testAcceptsArrayOfLabels(): void
    {
        Prompt::fake([Key::DOWN, Key::ENTER]);

        $result = select(
            label: 'What is your favorite color?',
            options: [
                'Red',
                'Green',
                'Blue',
            ]
        );

        $this->assertSame('Green', $result);
    }

    public function testAcceptsArrayOfKeysAndLabels(): void
    {
        Prompt::fake([Key::DOWN, Key::ENTER]);

        $result = select(
            label: 'What is your favorite color?',
            options: [
                'red' => 'Red',
                'green' => 'Green',
                'blue' => 'Blue',
            ]
        );

        $this->assertSame('green', $result);
    }

    public function testAcceptsAssociativeArrayWithIntegerKeys(): void
    {
        Prompt::fake([Key::DOWN, Key::ENTER]);

        $result = select(
            label: 'What is your favorite color?',
            options: [
                1 => 'Red',
                2 => 'Green',
                3 => 'Blue',
            ]
        );

        $this->assertSame(2, $result);
    }

    public function testAcceptsCollection(): void
    {
        Prompt::fake([Key::DOWN, Key::ENTER]);

        $result = select(
            label: 'What is your favorite color?',
            options: collect([
                'Red',
                'Green',
                'Blue',
            ]),
        );

        $this->assertSame('Green', $result);
    }

    /**
     * @dataProvider scrollOptionsProvider
     */
    public function testScrollsToBottomWhenDefaultValueIsNearEnd(int $scroll, array $outputContains): void
    {
        Prompt::fake([Key::ENTER]);

        $result = select(
            label: 'What is your favorite color?',
            options: [
                'Red',
                'Green',
                'Blue',
                'Yellow',
                'Orange',
                'Purple',
                'Pink',
                'Brown',
                'Black',
            ],
            default: 'Brown',
            scroll: $scroll
        );

        $this->assertSame('Brown', $result);

        foreach ($outputContains as $output) {
            Prompt::assertOutputContains($output);
        }
    }

    public static function scrollOptionsProvider(): array
    {
        return [
            'odd' => [
                'scroll' => 5,
                'outputContains' => [
                    'Orange',
                    'Purple',
                    'Pink',
                    'Brown',
                    'Black',
                ],
            ],
            'even' => [
                'scroll' => 6,
                'outputContains' => [
                    'Yellow',
                    'Orange',
                    'Purple',
                    'Pink',
                    'Brown',
                    'Black',
                ],
            ],
        ];
    }

    public function testSupportsEmacsStyleKeyBinding(): void
    {
        Prompt::fake([Key::CTRL_N, Key::CTRL_P, Key::CTRL_N, Key::ENTER]);

        $result = select(
            label: 'What is your favorite color?',
            options: [
                'Red',
                'Green',
                'Blue',
            ]
        );

        $this->assertSame('Green', $result);
    }

    public function testValidatesDefaultValueWhenNonInteractive(): void
    {
        $this->expectException(NonInteractiveValidationException::class);
        $this->expectExceptionMessage('Required.');

        Prompt::interactive(false);
        select(
            label: 'What is your favorite color?',
            options: [
                'None',
                'Red',
                'Green',
                'Blue',
            ],
            default: 'None',
            validate: fn ($value) => $value === 'None' ? 'Required.' : null,
        );
    }

    public function testSupportsCustomValidation(): void
    {
        Prompt::fake([Key::ENTER, Key::DOWN, Key::ENTER]);

        Prompt::validateUsing(function (Prompt $prompt) {
            $this->assertSame('What is your favorite color?', $prompt->label);
            $this->assertSame('in:green', $prompt->validate);
            return $prompt->validate === 'in:green' && $prompt->value() != 'green' ? 'Please choose green.' : null;
        });

        $result = select(
            label: 'What is your favorite color?',
            options: [
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
