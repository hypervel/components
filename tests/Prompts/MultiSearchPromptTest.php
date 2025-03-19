<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Key;
use Hypervel\Prompts\MultiSearchPrompt;
use Hypervel\Prompts\Prompt;
use PHPUnit\Framework\TestCase;

use function Hypervel\Prompts\multisearch;

/**
 * @backupStaticProperties enabled
 * @internal
 * @coversNothing
 */
class MultiSearchPromptTest extends TestCase
{
    public function testSupportsDefaultResults()
    {
        $promptFake = function () {
            Prompt::fake([
                Key::UP, // Highlight "Violet"
                Key::SPACE, // Select "Violet"
                'G', // Search for "Green"
                'r', // Search for "Green"
                Key::DOWN, // Highlight "Green"
                Key::SPACE, // Select "Green"
                Key::BACKSPACE, // Clear search
                Key::BACKSPACE, // Clear search
                Key::ENTER, // Confirm selection
            ]);
        };

        $promptFake();

        $result = multisearch(
            label: 'What are your favorite colors?',
            placeholder: 'Search...',
            options: function ($value) {
                $options = [
                    'red' => 'Red',
                    'orange' => 'Orange',
                    'yellow' => 'Yellow',
                    'green' => 'Green',
                    'blue' => 'Blue',
                    'indigo' => 'Indigo',
                    'violet' => 'Violet',
                ];

                if (strlen($value) === 0) {
                    return $options;
                }

                return array_filter($options, fn ($label) => str_contains(strtolower($label), strtolower($value)));
            },
        );

        $assertPrompt = function () {
            Prompt::assertStrippedOutputContains(<<<'OUTPUT'
         ┌ What are your favorite colors? ──────────────────────────────┐
         │ Search...                                                    │
         ├──────────────────────────────────────────────────────────────┤
         │   ◻ Red                                                    ┃ │
         │   ◻ Orange                                                 │ │
         │   ◻ Yellow                                                 │ │
         │   ◻ Green                                                  │ │
         │   ◻ Blue                                                   │ │
         └────────────────────────────────────────────────── 0 selected ┘
        OUTPUT);

            Prompt::assertStrippedOutputContains(<<<'OUTPUT'
         ┌ What are your favorite colors? ──────────────────────────────┐
         │ Search...                                                    │
         ├──────────────────────────────────────────────────────────────┤
         │   ◻ Yellow                                                 │ │
         │   ◻ Green                                                  │ │
         │   ◻ Blue                                                   │ │
         │   ◻ Indigo                                                 │ │
         │ › ◻ Violet                                                 ┃ │
         └────────────────────────────────────────────────── 0 selected ┘
        OUTPUT);

            Prompt::assertStrippedOutputContains(<<<'OUTPUT'
         ┌ What are your favorite colors? ──────────────────────────────┐
         │ Search...                                                    │
         ├──────────────────────────────────────────────────────────────┤
         │   ◻ Yellow                                                 │ │
         │   ◻ Green                                                  │ │
         │   ◻ Blue                                                   │ │
         │   ◻ Indigo                                                 │ │
         │ › ◼ Violet                                                 ┃ │
         └────────────────────────────────────────────────── 1 selected ┘
        OUTPUT);

            Prompt::assertStrippedOutputContains(<<<'OUTPUT'
         ┌ What are your favorite colors? ──────────────────────────────┐
         │ Gr                                                           │
         ├──────────────────────────────────────────────────────────────┤
         │   ◻ Green                                                    │
         └─────────────────────────────────────── 1 selected (1 hidden) ┘
        OUTPUT);

            Prompt::assertStrippedOutputContains(<<<'OUTPUT'
         ┌ What are your favorite colors? ──────────────────────────────┐
         │ Gr                                                           │
         ├──────────────────────────────────────────────────────────────┤
         │ › ◻ Green                                                    │
         └─────────────────────────────────────── 1 selected (1 hidden) ┘
        OUTPUT);

            Prompt::assertStrippedOutputContains(<<<'OUTPUT'
         ┌ What are your favorite colors? ──────────────────────────────┐
         │ Gr                                                           │
         ├──────────────────────────────────────────────────────────────┤
         │ › ◼ Green                                                    │
         └─────────────────────────────────────── 2 selected (1 hidden) ┘
        OUTPUT);

            Prompt::assertStrippedOutputContains(<<<'OUTPUT'
         ┌ What are your favorite colors? ──────────────────────────────┐
         │ Search...                                                    │
         ├──────────────────────────────────────────────────────────────┤
         │   ◻ Red                                                    ┃ │
         │   ◻ Orange                                                 │ │
         │   ◻ Yellow                                                 │ │
         │   ◼ Green                                                  │ │
         │   ◻ Blue                                                   │ │
         └────────────────────────────────────────────────── 2 selected ┘
        OUTPUT);
        };

        $assertPrompt();

        $this->assertSame(['violet', 'green'], $result);

        $promptFake();

        $result = multisearch(
            label: 'What are your favorite colors?',
            placeholder: 'Search...',
            options: function ($value) {
                $options = ['Red', 'Orange', 'Yellow', 'Green', 'Blue', 'Indigo', 'Violet'];

                if (strlen($value) === 0) {
                    return $options;
                }

                return array_values(array_filter($options, fn ($label) => str_contains(strtolower($label), strtolower($value))));
            },
        );

        $assertPrompt();

        $this->assertSame(['Violet', 'Green'], $result);
    }

    public function testSupportsNoDefaultResults()
    {
        $promptFake = function () {
            Prompt::fake([
                'V', // Search for "Violet"
                Key::UP, // Highlight "Violet"
                Key::SPACE, // Select "Violet"
                Key::BACKSPACE, // Clear search
                'G', // Search for "Green"
                'r', // Search for "Green"
                Key::DOWN, // Highlight "Green"
                Key::SPACE, // Select "Green"
                Key::BACKSPACE, // Clear search
                Key::BACKSPACE, // Clear search
                Key::ENTER, // Confirm selection
            ]);
        };

        $promptFake();

        $result = multisearch(
            label: 'What are your favorite colors?',
            placeholder: 'Search...',
            options: fn ($value) => strlen($value) > 0 ? array_filter([
                'red' => 'Red',
                'orange' => 'Orange',
                'yellow' => 'Yellow',
                'green' => 'Green',
                'blue' => 'Blue',
                'indigo' => 'Indigo',
                'violet' => 'Violet',
            ], fn ($label) => str_contains(strtolower($label), strtolower($value))) : [],
        );

        $assertPrompt = function () {
            Prompt::assertStrippedOutputContains(<<<'OUTPUT'
         ┌ What are your favorite colors? ──────────────────────────────┐
         │ Search...                                                    │
         └────────────────────────────────────────────────── 0 selected ┘
        OUTPUT);

            Prompt::assertStrippedOutputContains(<<<'OUTPUT'
         ┌ What are your favorite colors? ──────────────────────────────┐
         │ V                                                            │
         ├──────────────────────────────────────────────────────────────┤
         │   ◻ Violet                                                   │
         └────────────────────────────────────────────────── 0 selected ┘
        OUTPUT);

            Prompt::assertStrippedOutputContains(<<<'OUTPUT'
         ┌ What are your favorite colors? ──────────────────────────────┐
         │ V                                                            │
         ├──────────────────────────────────────────────────────────────┤
         │ › ◻ Violet                                                   │
         └────────────────────────────────────────────────── 0 selected ┘
        OUTPUT);

            Prompt::assertStrippedOutputContains(<<<'OUTPUT'
         ┌ What are your favorite colors? ──────────────────────────────┐
         │ V                                                            │
         ├──────────────────────────────────────────────────────────────┤
         │ › ◼ Violet                                                   │
         └────────────────────────────────────────────────── 1 selected ┘
        OUTPUT);

            Prompt::assertStrippedOutputContains(<<<'OUTPUT'
         ┌ What are your favorite colors? ──────────────────────────────┐
         │ Search...                                                    │
         ├──────────────────────────────────────────────────────────────┤
         │   ◼ Violet                                                   │
         └────────────────────────────────────────────────── 1 selected ┘
        OUTPUT);

            Prompt::assertStrippedOutputContains(<<<'OUTPUT'
         ┌ What are your favorite colors? ──────────────────────────────┐
         │ Gr                                                           │
         ├──────────────────────────────────────────────────────────────┤
         │   ◻ Green                                                    │
         └─────────────────────────────────────── 1 selected (1 hidden) ┘
        OUTPUT);

            Prompt::assertStrippedOutputContains(<<<'OUTPUT'
         ┌ What are your favorite colors? ──────────────────────────────┐
         │ Gr                                                           │
         ├──────────────────────────────────────────────────────────────┤
         │ › ◻ Green                                                    │
         └─────────────────────────────────────── 1 selected (1 hidden) ┘
        OUTPUT);

            Prompt::assertStrippedOutputContains(<<<'OUTPUT'
         ┌ What are your favorite colors? ──────────────────────────────┐
         │ Gr                                                           │
         ├──────────────────────────────────────────────────────────────┤
         │ › ◼ Green                                                    │
         └─────────────────────────────────────── 2 selected (1 hidden) ┘
        OUTPUT);

            Prompt::assertStrippedOutputContains(<<<'OUTPUT'
         ┌ What are your favorite colors? ──────────────────────────────┐
         │ Search...                                                    │
         ├──────────────────────────────────────────────────────────────┤
         │   ◼ Violet                                                   │
         │   ◼ Green                                                    │
         └────────────────────────────────────────────────── 2 selected ┘
        OUTPUT);

            Prompt::assertStrippedOutputContains(<<<'OUTPUT'
         ┌ What are your favorite colors? ──────────────────────────────┐
         │ Violet                                                       │
         │ Green                                                        │
         └──────────────────────────────────────────────────────────────┘
        OUTPUT);
        };

        $assertPrompt();

        $this->assertSame(['violet', 'green'], $result);

        $promptFake();

        $result = multisearch(
            label: 'What are your favorite colors?',
            placeholder: 'Search...',
            options: fn ($value) => strlen($value) > 0 ? array_values(array_filter(['Red', 'Orange', 'Yellow', 'Green', 'Blue', 'Indigo', 'Violet'], fn ($label) => str_contains(strtolower($label), strtolower($value)))) : [],
        );

        $assertPrompt();

        $this->assertSame(['Violet', 'Green'], $result);
    }

    public function testTransformsValues()
    {
        Prompt::fake([Key::DOWN, Key::CTRL_A, Key::ENTER]);

        $result = multisearch(
            label: 'What are your favorite colors?',
            options: fn () => [
                'red' => 'Red',
                'green' => 'Green',
                'blue' => 'Blue',
            ],
            transform: fn ($value) => array_map('strtoupper', $value),
        );

        $this->assertSame(['RED', 'GREEN', 'BLUE'], $result);
    }

    public function testValidates()
    {
        Prompt::fake(['a', Key::DOWN, Key::SPACE, Key::ENTER, Key::DOWN, Key::SPACE, Key::ENTER]);

        $result = multisearch(
            label: 'What are your favorite colors?',
            options: fn () => [
                'red' => 'Red',
                'green' => 'Green',
                'blue' => 'Blue',
            ],
            validate: fn ($value) => ! in_array('green', $value) ? 'Please choose green.' : null
        );

        $this->assertSame(['red', 'green'], $result);

        Prompt::assertOutputContains('Please choose green.');
    }

    public function testSupportsTheHomeAndEndKeysWhileNavigatingOptions()
    {
        Prompt::fake([Key::DOWN, Key::END[0], Key::SPACE, Key::HOME[0], Key::SPACE, Key::ENTER]);

        $result = multisearch(
            label: 'What are your favorite colors?',
            options: fn () => [
                'red' => 'Red',
                'green' => 'Green',
                'blue' => 'Blue',
            ]
        );

        $this->assertSame(['blue', 'red'], $result);
    }

    public function testCanFallback()
    {
        Prompt::fallbackWhen(true);

        MultiSearchPrompt::fallbackUsing(function (MultiSearchPrompt $prompt) {
            $this->assertSame('What are your favorite colors?', $prompt->label);

            return ['result'];
        });

        $result = multisearch(
            label: 'What are your favorite colors?',
            options: fn () => [
                'red' => 'Red',
                'green' => 'Green',
                'blue' => 'Blue',
            ],
        );

        $this->assertSame(['result'], $result);
    }

    public function testSupportsCustomValidation()
    {
        Prompt::fake(['a', Key::DOWN, Key::SPACE, Key::ENTER, Key::DOWN, Key::SPACE, Key::ENTER]);

        Prompt::validateUsing(function (Prompt $prompt) {
            $this->assertSame('What are your favorite colors?', $prompt->label);
            $this->assertSame('in:green', $prompt->validate);

            return $prompt->validate === 'in:green' && ! in_array('green', $prompt->value()) ? 'And green?' : null;
        });

        $result = multisearch(
            label: 'What are your favorite colors?',
            options: fn () => [
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

    public function testSupportsSelectingAllOptions()
    {
        Prompt::fake([Key::DOWN, Key::CTRL_A, Key::ENTER]);

        $result = multisearch(
            label: 'What are your favorite colors?',
            options: fn () => [
                'red' => 'Red',
                'green' => 'Green',
                'blue' => 'Blue',
            ],
        );

        $this->assertSame(['red', 'green', 'blue'], $result);

        Prompt::fake([Key::DOWN, Key::CTRL_A, Key::CTRL_A, Key::ENTER]);

        $result = multisearch(
            label: 'What are your favorite colors?',
            options: fn () => [
                'red' => 'Red',
                'green' => 'Green',
                'blue' => 'Blue',
            ],
        );

        $this->assertSame([], $result);
    }
}
