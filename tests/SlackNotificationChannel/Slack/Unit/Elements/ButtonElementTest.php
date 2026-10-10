<?php

declare(strict_types=1);

namespace Hypervel\Tests\SlackNotificationChannel\Slack\Unit\Elements;

use Hypervel\Notifications\Slack\BlockKit\Composites\ConfirmObject;
use Hypervel\Notifications\Slack\BlockKit\Composites\PlainTextOnlyTextObject;
use Hypervel\Notifications\Slack\BlockKit\Elements\ButtonElement;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class ButtonElementTest extends TestCase
{
    public function testItIsArrayable(): void
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

    public function testTheMaximumTextLengthIs75Characters(): void
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

    public function testTheTextCanBeCustomized(): void
    {
        $element = new ButtonElement('Click Me', function (PlainTextOnlyTextObject $textObject): void {
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

    public function testTheActionIdCanBeCustomized(): void
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

    public function testTheActionIdCannotExceed255Characters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Maximum length for the action_id field is 255 characters.');

        $element = new ButtonElement('Click Me');
        $element->id(str_repeat('a', 256));

        $element->toArray();
    }

    public function testGeneratedActionIdCannotExceedTwoFiveFiveCharacters(): void
    {
        $element = new ButtonElement(str_repeat('@', 248));

        $this->assertSame(255, strlen($element->toArray()['action_id']));
    }

    public function testGeneratedActionIdsFallbackWhenTextCannotBeSlugged(): void
    {
        $firstId = (new ButtonElement('🦄'))->toArray()['action_id'];
        $secondId = (new ButtonElement('🐘'))->toArray()['action_id'];

        $this->assertStringStartsWith('button_', $firstId);
        $this->assertStringStartsWith('button_', $secondId);
        $this->assertNotSame('button_', $firstId);
        $this->assertNotSame($firstId, $secondId);
    }

    public function testItCanHaveAnUrl(): void
    {
        $element = new ButtonElement('Click Me');
        $element->url('https://hypervel.org');

        $this->assertSame([
            'type' => 'button',
            'text' => [
                'type' => 'plain_text',
                'text' => 'Click Me',
            ],
            'action_id' => 'button_click-me',
            'url' => 'https://hypervel.org',
        ], $element->toArray());
    }

    public function testTheUrlCannotExceed3000Characters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Maximum length for the url field is 3000 characters.');

        $element = new ButtonElement('Click Me');
        $element->url(str_repeat('a', 3001));

        $element->toArray();
    }

    public function testItCanHaveAValue(): void
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

    public function testCharacterLimitedFieldsAcceptMultibyteValuesAtTheirLimits(): void
    {
        $url = str_repeat('你', 3000);
        $id = str_repeat('你', 255);
        $value = str_repeat('你', 2000);
        $label = str_repeat('你', 75);

        $element = (new ButtonElement('Click Me'))
            ->url($url)
            ->id($id)
            ->value($value)
            ->accessibilityLabel($label);

        $payload = $element->toArray();

        $this->assertSame($url, $payload['url']);
        $this->assertSame($id, $payload['action_id']);
        $this->assertSame($value, $payload['value']);
        $this->assertSame($label, $payload['accessibility_label']);
    }

    public function testZeroValueAndAccessibilityLabelArePreserved(): void
    {
        $payload = (new ButtonElement('Zero'))->value('0')->accessibilityLabel('0')->toArray();

        $this->assertSame('0', $payload['value']);
        $this->assertSame('0', $payload['accessibility_label']);
    }

    public function testTheValueCannotExceed2000Characters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Maximum length for the value field is 2000 characters.');

        $element = new ButtonElement('Click Me');
        $element->value(str_repeat('a', 2001));

        $element->toArray();
    }

    public function testItCanHaveThePrimaryStyle(): void
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

    public function testItCanHaveTheDangerStyle(): void
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

    public function testItCanHaveAConfirmationDialog(): void
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

    public function testItCanScopeTheConfirmationDialogAndSetMultipleOptions(): void
    {
        $element = new ButtonElement('Click Me');
        $element->confirm('This will do some thing.', function (ConfirmObject $dialog): void {
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

    public function testItCanHaveAnAccessibilityLabel(): void
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

    public function testTheAccessibilityLabelCannotExceed75Characters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Maximum length for the accessibility label is 75 characters.');

        $element = new ButtonElement('Click Me');
        $element->accessibilityLabel(str_repeat('a', 76));

        $element->toArray();
    }
}
