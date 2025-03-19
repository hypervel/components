<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Exceptions\NonInteractiveValidationException;
use Hypervel\Prompts\Key;
use Hypervel\Prompts\MultiSelectPrompt;
use Hypervel\Prompts\Prompt;
use PHPUnit\Framework\TestCase;

use function Hypervel\Prompts\multiselect;

/**
 * @backupStaticProperties enabled
 * @internal
 * @coversNothing
 */
class MultiSelectPromptTest extends TestCase
{
    public function testAcceptsAnArrayOfLabels()
    {
        Prompt::fake([Key::DOWN, Key::SPACE, Key::DOWN, Key::SPACE, Key::ENTER]);

        $result = multiselect(
            label: 'What are your favorite colors?',
            options: [
                'Red',
                'Green',
                'Blue',
            ]
        );

        $this->assertSame(['Green', 'Blue'], $result);

        Prompt::assertStrippedOutputDoesntContain('│ Red');
        Prompt::assertStrippedOutputContains('│ Green');
        Prompt::assertStrippedOutputContains('│ Blue');
    }

    public function testAcceptsAnArrayOfKeysAndLabels()
    {
        Prompt::fake([Key::DOWN, Key::SPACE, Key::DOWN, Key::SPACE, Key::ENTER]);

        $result = multiselect(
            label: 'What are your favorite colors?',
            options: [
                'red' => 'Red',
                'green' => 'Green',
                'blue' => 'Blue',
            ]
        );

        $this->assertSame(['green', 'blue'], $result);
    }

    public function testAcceptsAnAssociateArrayWithIntegerKeys()
    {
        Prompt::fake([Key::DOWN, Key::SPACE, Key::DOWN, Key::SPACE, Key::ENTER]);

        $result = multiselect(
            label: 'What are your favorite colors?',
            options: [
                1 => 'Red',
                2 => 'Green',
                3 => 'Blue',
            ]
        );

        $this->assertSame([2, 3], $result);
    }

    public function testAcceptsDefaultValuesWhenTheOptionsAreLabel()
    {
        Prompt::fake([Key::ENTER]);

        $result = multiselect(
            label: 'What are your favorite colors?',
            options: [
                'Red',
                'Green',
                'Blue',
            ],
            default: ['Green']
        );

        $this->assertSame(['Green'], $result);
    }

    public function testAcceptsDefaultValuesWhenOptionsAreKeysWithLabels(): void
    {
        Prompt::fake([Key::ENTER]);

        $result = multiselect(
            label: 'What are your favorite colors?',
            options: [
                'red' => 'Red',
                'green' => 'Green',
                'blue' => 'Blue',
            ],
            default: ['green']
        );

        $this->assertSame(['green'], $result);
    }

    public function testAcceptsCollections(): void
    {
        Prompt::fake([Key::ENTER]);

        $result = multiselect(
            label: 'What are your favorite colors?',
            options: collect([
                'Red',
                'Green',
                'Blue',
            ]),
            default: collect(['Green'])
        );

        $this->assertSame(['Green'], $result);
    }

    public function testTransformsValues(): void
    {
        Prompt::fake([Key::DOWN, Key::SPACE, Key::DOWN, Key::SPACE, Key::ENTER]);

        $result = multiselect(
            label: 'What are your favorite colors?',
            options: [
                'red' => 'Red',
                'green' => 'Green',
                'blue' => 'Blue',
            ],
            transform: fn ($value) => array_map('strtoupper', $value),
        );

        $this->assertSame(['GREEN', 'BLUE'], $result);
    }

    public function testValidates(): void
    {
        Prompt::fake([Key::ENTER, Key::SPACE, Key::ENTER]);

        $result = multiselect(
            label: 'What are your favorite colors?',
            options: [
                'red' => 'Red',
                'green' => 'Green',
                'blue' => 'Blue',
            ],
            validate: fn ($values) => count($values) === 0 ? 'You must select at least one color.' : null
        );

        $this->assertSame(['red'], $result);
        Prompt::assertOutputContains('You must select at least one color.');
    }

    public function testCanFallBack(): void
    {
        Prompt::fallbackWhen(true);

        MultiSelectPrompt::fallbackUsing(function (MultiSelectPrompt $prompt) {
            $this->assertSame('What is your favorite color?', $prompt->label);
            return ['Blue'];
        });

        $result = multiselect('What is your favorite color?', [
            'Red',
            'Green',
            'Blue',
        ]);

        $this->assertSame(['Blue'], $result);

        Prompt::fallbackWhen(false);
    }

    public function testSupportsEmacsStyleKeyBinding(): void
    {
        Prompt::fake([Key::CTRL_N, Key::SPACE, Key::CTRL_N, Key::SPACE, Key::ENTER]);

        $result = multiselect(
            label: 'What are your favorite colors?',
            options: [
                'red' => 'Red',
                'green' => 'Green',
                'blue' => 'Blue',
            ]
        );

        $this->assertSame(['green', 'blue'], $result);
    }

    public function testSupportsHomeAndEndKeys(): void
    {
        Prompt::fake([Key::END[0], Key::SPACE, Key::HOME[0], Key::SPACE, Key::ENTER]);

        $result = multiselect(
            label: 'What are your favorite colors?',
            options: [
                'red' => 'Red',
                'green' => 'Green',
                'blue' => 'Blue',
            ]
        );

        $this->assertSame(['blue', 'red'], $result);
    }

    public function testSupportsCustomValidation(): void
    {
        Prompt::fake([Key::SPACE, Key::ENTER, Key::DOWN, Key::SPACE, Key::ENTER]);

        Prompt::validateUsing(function (Prompt $prompt) {
            $this->assertSame('What are your favorite colors?', $prompt->label);
            $this->assertSame('in:green', $prompt->validate);
            return $prompt->validate === 'in:green' && ! in_array('green', $prompt->value()) ? 'And green?' : null;
        });

        $result = multiselect(
            label: 'What are your favorite colors?',
            options: [
                'red' => 'Red',
                'green' => 'Green',
                'blue' => 'Blue',
            ],
            validate: 'in:green',
        );

        $this->assertSame(['red', 'green'], $result);
        Prompt::assertOutputContains('And green?');

        Prompt::validateUsing(fn () => null);
    }

    public function testReturnsEmptyArrayWhenNonInteractive(): void
    {
        Prompt::interactive(false);

        $result = multiselect('What is your favorite color?', [
            'Red',
            'Green',
            'Blue',
        ]);

        $this->assertSame([], $result);
    }

    public function testReturnsDefaultValueWhenNonInteractive(): void
    {
        Prompt::interactive(false);

        $result = multiselect('What is your favorite color?', [
            'Red',
            'Green',
            'Blue',
        ], default: ['Green']);

        $this->assertSame(['Green'], $result);
    }

    public function testValidatesDefaultValueWhenNonInteractive(): void
    {
        $this->expectException(NonInteractiveValidationException::class);
        $this->expectExceptionMessage('Required.');

        Prompt::interactive(false);
        multiselect('What is your favorite color?', [
            'Red',
            'Green',
            'Blue',
        ], required: true);
    }
}
