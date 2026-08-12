<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications\Slack\Elements\Selects;

use Hypervel\Notifications\Slack\BlockKit\Elements\Selects\StaticSelectElement;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use LogicException;

class StaticSelectElementTest extends TestCase
{
    public function testItCanAddInitialOption(): void
    {
        $select = new StaticSelectElement;
        $select->id('initial_option_select');
        $select->addOption('Option A', 'option_a');
        $select->initialOption('option_a');

        $this->assertSame([
            'type' => 'static_select',
            'options' => [
                [
                    'text' => [
                        'type' => 'plain_text',
                        'text' => 'Option A',
                    ],
                    'value' => 'option_a',
                ],
            ],
            'initial_option' => [
                'text' => [
                    'type' => 'plain_text',
                    'text' => 'Option A',
                ],
                'value' => 'option_a',
            ],
            'action_id' => 'initial_option_select',
        ], $select->toArray());
    }

    public function testItCanEnableFocusOnLoad(): void
    {
        $select = new StaticSelectElement;
        $select->id('enable_focus');
        $select->addOption('Option A', 'option_a');
        $select->focus(true);

        $this->assertSame([
            'type' => 'static_select',
            'options' => [[
                'text' => [
                    'type' => 'plain_text',
                    'text' => 'Option A',
                ],
                'value' => 'option_a',
            ]],
            'action_id' => 'enable_focus',
            'focus_on_load' => true,
        ], $select->toArray());
    }

    public function testItRejectsInvalidPlaceholderText(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Text must be at least 1 character(s) long.');

        $select = new StaticSelectElement;
        $select->id('invalid_placeholder');
        $select->placeholder('');
    }

    public function testItCanAddMultipleOptions(): void
    {
        $select = new StaticSelectElement;
        $select->id('multi_select');
        $select->addOption('Option A', 'option_a');
        $select->addOption('Option B', 'option_b');
        $select->addOption('Option C', 'option_c');

        $this->assertSame([
            'type' => 'static_select',
            'options' => [
                [
                    'text' => [
                        'type' => 'plain_text',
                        'text' => 'Option A',
                    ],
                    'value' => 'option_a',
                ],
                [
                    'text' => [
                        'type' => 'plain_text',
                        'text' => 'Option B',
                    ],
                    'value' => 'option_b',
                ],
                [
                    'text' => [
                        'type' => 'plain_text',
                        'text' => 'Option C',
                    ],
                    'value' => 'option_c',
                ],
            ],
            'action_id' => 'multi_select',
        ], $select->toArray());
    }

    public function testItCanSetPlaceholder(): void
    {
        $select = new StaticSelectElement;
        $select->id('placeholder_select');
        $select->addOption('Option A', 'option_a');
        $select->placeholder('Choose an option');

        $this->assertSame([
            'type' => 'static_select',
            'options' => [[
                'text' => [
                    'type' => 'plain_text',
                    'text' => 'Option A',
                ],
                'value' => 'option_a',
            ]],
            'action_id' => 'placeholder_select',
            'placeholder' => [
                'type' => 'plain_text',
                'text' => 'Choose an option',
            ],
        ], $select->toArray());
    }

    public function testItCanDisableFocusOnLoad(): void
    {
        $select = new StaticSelectElement;
        $select->id('disable_focus');
        $select->addOption('Option A', 'option_a');
        $select->focus(false);

        $this->assertSame([
            'type' => 'static_select',
            'options' => [[
                'text' => [
                    'type' => 'plain_text',
                    'text' => 'Option A',
                ],
                'value' => 'option_a',
            ]],
            'action_id' => 'disable_focus',
            'focus_on_load' => false,
        ], $select->toArray());
    }

    public function testItRejectsInitialOptionWhenNoOptionsAvailable(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown option value: non_existent_option.');

        $select = new StaticSelectElement;
        $select->id('no_options');
        $select->initialOption('non_existent_option');
    }

    public function testItGeneratesADeterministicActionIdFromText(): void
    {
        $select = new StaticSelectElement('Example Select');
        $select->addOption('Option A', 'option_a');

        $this->assertSame('static_select_example-select', $select->toArray()['action_id']);
    }

    public function testGeneratedActionIdRespectsTheSlackLimit(): void
    {
        $select = new StaticSelectElement(str_repeat('a', 248));
        $select->addOption('Option A', 'option_a');

        $this->assertSame(255, strlen($select->toArray()['action_id']));
    }

    public function testDirectConstructionWithoutTextGeneratesAnActionId(): void
    {
        $select = new StaticSelectElement;
        $select->addOption('Option A', 'option_a');

        $actionId = $select->toArray()['action_id'];

        $this->assertStringStartsWith('static_select_', $actionId);
        $this->assertNotSame('static_select_', $actionId);
    }

    public function testExplicitActionIdCannotExceedTheSlackLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum length for the action_id field is 255 characters.');

        (new StaticSelectElement)->id(str_repeat('a', 256));
    }

    public function testExplicitActionIdUsesTheSlackCharacterLimit(): void
    {
        $id = str_repeat('你', 255);
        $select = (new StaticSelectElement)->id($id)->addOption('Option A', 'option_a');

        $this->assertSame($id, $select->toArray()['action_id']);
    }

    public function testOptionValuesRemainDistinctInteractionIdentities(): void
    {
        $select = new StaticSelectElement;
        $select->addOption('Spaced', 'A B');
        $select->addOption('Compact', 'ab');
        $select->initialOption('A B');

        $payload = $select->toArray();

        $this->assertSame(['A B', 'ab'], array_column($payload['options'], 'value'));
        $this->assertSame('A B', $payload['initial_option']['value']);
    }

    public function testPlaceholderUsesTheSlackCharacterLimit(): void
    {
        $atLimit = new StaticSelectElement;
        $atLimit->addOption('Option A', 'option_a');
        $atLimit->placeholder(str_repeat('你', 150));

        $overLimit = new StaticSelectElement;
        $overLimit->addOption('Option A', 'option_a');
        $overLimit->placeholder(str_repeat('你', 151));

        $placeholder = $overLimit->toArray()['placeholder']['text'];

        $this->assertSame(str_repeat('你', 150), $atLimit->toArray()['placeholder']['text']);
        $this->assertSame(150, mb_strlen($placeholder, 'UTF-8'));
        $this->assertSame(str_repeat('你', 147) . '...', $placeholder);
    }

    public function testItRejectsAnEmptyOptionList(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('There must be at least one option in each static select element.');

        (new StaticSelectElement)->toArray();
    }

    public function testItAcceptsOneHundredOptionsAndReplacementAtTheLimit(): void
    {
        $select = new StaticSelectElement;

        foreach (range(1, 100) as $option) {
            $select->addOption("Option {$option}", "option_{$option}");
        }

        $select->addOption('Replacement', 'option_100');

        $options = $select->toArray()['options'];

        $this->assertCount(100, $options);
        $this->assertSame('Replacement', $options[99]['text']['text']);
    }

    public function testItRejectsMoreThanOneHundredOptions(): void
    {
        $select = new StaticSelectElement;

        foreach (range(1, 101) as $option) {
            $select->addOption("Option {$option}", "option_{$option}");
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('There is a maximum of 100 options in each static select element.');

        $select->toArray();
    }
}
