<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications\Slack\Elements\Selects;

use Hypervel\Notifications\Slack\BlockKit\Elements\Selects\StaticSelectElement;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

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
        $select->focus(true);

        $this->assertSame([
            'type' => 'static_select',
            'options' => [],
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
        $select->placeholder('Choose an option');

        $this->assertSame([
            'type' => 'static_select',
            'options' => [],
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
        $select->focus(false);

        $this->assertSame([
            'type' => 'static_select',
            'options' => [],
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

        $this->assertSame('static_select_example-select', $select->toArray()['action_id']);
    }

    public function testGeneratedActionIdRespectsTheSlackLimit(): void
    {
        $select = new StaticSelectElement(str_repeat('a', 248));

        $this->assertSame(255, strlen($select->toArray()['action_id']));
    }

    public function testDirectConstructionWithoutTextGeneratesAnActionId(): void
    {
        $actionId = (new StaticSelectElement)->toArray()['action_id'];

        $this->assertStringStartsWith('static_select_', $actionId);
        $this->assertNotSame('static_select_', $actionId);
    }

    public function testExplicitActionIdCannotExceedTheSlackLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum length for the action_id field is 255 characters.');

        (new StaticSelectElement)->id(str_repeat('a', 256));
    }
}
