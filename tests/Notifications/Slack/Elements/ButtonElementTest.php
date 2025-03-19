<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications\Slack\Elements;

use Hypervel\Notifications\Slack\BlockKit\Composites\ConfirmObject;
use Hypervel\Notifications\Slack\BlockKit\Composites\PlainTextOnlyTextObject;
use Hypervel\Notifications\Slack\BlockKit\Elements\ButtonElement;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ButtonElementTest extends TestCase
{
    public function testArrayable(): void
    {
        $element = new ButtonElement('Click Me');

        $this->assertSame([
            'type' => 'button',
            'text' => [
                'type' => 'plain_text',
                'text' => 'Click Me',
            ],
            'action_id' => 'button_click-me',
        ], $element->toArray());
    }

    public function testTextLengthIsSeventyFiveCharacters(): void
    {
        $element = new ButtonElement(str_repeat('a', 250));

        $this->assertSame([
            'type' => 'button',
            'text' => [
                'type' => 'plain_text',
                'text' => str_repeat('a', 72) . '...',
            ],
            'action_id' => 'button_' . str_repeat('a', 248),
        ], $element->toArray());
    }

    public function testTextCanBeCustomized(): void
    {
        $element = new ButtonElement('Click Me', function (PlainTextOnlyTextObject $textObject) {
            $textObject->emoji();
        });

        $this->assertSame([
            'type' => 'button',
            'text' => [
                'type' => 'plain_text',
                'text' => 'Click Me',
                'emoji' => true,
            ],
            'action_id' => 'button_click-me',
        ], $element->toArray());
    }

    public function testActionIdCanBeCustomized(): void
    {
        $element = new ButtonElement('Click Me');
        $element->id('custom_action_id');

        $this->assertSame([
            'type' => 'button',
            'text' => [
                'type' => 'plain_text',
                'text' => 'Click Me',
            ],
            'action_id' => 'custom_action_id',
        ], $element->toArray());
    }

    public function testActionIdCantExceedTwoFiveFiveCharacters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum length for the action_id field is 255 characters.');

        $element = new ButtonElement('Click Me');
        $element->id(str_repeat('a', 256));

        $element->toArray();
    }

    public function testCanHaveUrl(): void
    {
        $element = new ButtonElement('Click Me');
        $element->url('https://laravel.com');

        $this->assertSame([
            'type' => 'button',
            'text' => [
                'type' => 'plain_text',
                'text' => 'Click Me',
            ],
            'action_id' => 'button_click-me',
            'url' => 'https://laravel.com',
        ], $element->toArray());
    }

    public function testUrlCantExceedThreeThousandCharacters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum length for the url field is 3000 characters.');

        $element = new ButtonElement('Click Me');
        $element->url(str_repeat('a', 3001));

        $element->toArray();
    }

    public function testCanHaveValue(): void
    {
        $element = new ButtonElement('Click Me');
        $element->value('click_me_123');

        $this->assertSame([
            'type' => 'button',
            'text' => [
                'type' => 'plain_text',
                'text' => 'Click Me',
            ],
            'action_id' => 'button_click-me',
            'value' => 'click_me_123',
        ], $element->toArray());
    }

    public function testValueCantExceedTwoThousandCharacters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum length for the value field is 2000 characters.');

        $element = new ButtonElement('Click Me');
        $element->value(str_repeat('a', 2001));

        $element->toArray();
    }

    public function testCanHavePrimaryStyle(): void
    {
        $element = new ButtonElement('Click Me');
        $element->primary();

        $this->assertSame([
            'type' => 'button',
            'text' => [
                'type' => 'plain_text',
                'text' => 'Click Me',
            ],
            'action_id' => 'button_click-me',
            'style' => 'primary',
        ], $element->toArray());
    }

    public function testCanHaveDangerStyle(): void
    {
        $element = new ButtonElement('Click Me');
        $element->danger();

        $this->assertSame([
            'type' => 'button',
            'text' => [
                'type' => 'plain_text',
                'text' => 'Click Me',
            ],
            'action_id' => 'button_click-me',
            'style' => 'danger',
        ], $element->toArray());
    }

    public function testCanHaveConfirmableDialog(): void
    {
        $element = new ButtonElement('Click Me');
        $element->confirm('This will do some thing.')->deny('Yikes!');

        $this->assertSame([
            'type' => 'button',
            'text' => [
                'type' => 'plain_text',
                'text' => 'Click Me',
            ],
            'action_id' => 'button_click-me',
            'confirm' => [
                'title' => [
                    'type' => 'plain_text',
                    'text' => 'Are you sure?',
                ],
                'text' => [
                    'type' => 'plain_text',
                    'text' => 'This will do some thing.',
                ],
                'confirm' => [
                    'type' => 'plain_text',
                    'text' => 'Yes',
                ],
                'deny' => [
                    'type' => 'plain_text',
                    'text' => 'Yikes!',
                ],
            ],
        ], $element->toArray());
    }

    public function testConfirmationWithMultipleOptions(): void
    {
        $element = new ButtonElement('Click Me');
        $element->confirm('This will do some thing.', function (ConfirmObject $dialog) {
            $dialog->deny('Yikes!');
            $dialog->confirm('Woohoo!');
        });

        $this->assertSame([
            'type' => 'button',
            'text' => [
                'type' => 'plain_text',
                'text' => 'Click Me',
            ],
            'action_id' => 'button_click-me',
            'confirm' => [
                'title' => [
                    'type' => 'plain_text',
                    'text' => 'Are you sure?',
                ],
                'text' => [
                    'type' => 'plain_text',
                    'text' => 'This will do some thing.',
                ],
                'confirm' => [
                    'type' => 'plain_text',
                    'text' => 'Woohoo!',
                ],
                'deny' => [
                    'type' => 'plain_text',
                    'text' => 'Yikes!',
                ],
            ],
        ], $element->toArray());
    }

    public function testCanHaveAccessibilityLabel(): void
    {
        $element = new ButtonElement('Click Me');
        $element->accessibilityLabel('Click Me Button');

        $this->assertSame([
            'type' => 'button',
            'text' => [
                'type' => 'plain_text',
                'text' => 'Click Me',
            ],
            'action_id' => 'button_click-me',
            'accessibility_label' => 'Click Me Button',
        ], $element->toArray());
    }

    public function testAccessibilityLabelCantExceedSeventyFiveCharacters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum length for the accessibility label is 75 characters.');

        $element = new ButtonElement('Click Me');
        $element->accessibilityLabel(str_repeat('a', 76));

        $element->toArray();
    }
}
