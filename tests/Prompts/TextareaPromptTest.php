<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Exceptions\NonInteractiveValidationException;
use Hypervel\Prompts\Key;
use Hypervel\Prompts\Prompt;
use Hypervel\Prompts\TextareaPrompt;
use PHPUnit\Framework\TestCase;

use function Hypervel\Prompts\textarea;

/**
 * @backupStaticProperties enabled
 * @internal
 * @coversNothing
 */
class TextareaPromptTest extends TestCase
{
    public function testReturnsTheInput(): void
    {
        Prompt::fake(['J', 'e', 's', 's', Key::ENTER, 'J', 'o', 'e', Key::CTRL_D]);
        $result = textarea(label: 'What is your name?');
        $this->assertSame("Jess\nJoe", $result);
    }

    public function testAcceptsDefaultValue(): void
    {
        Prompt::fake([Key::CTRL_D]);
        $result = textarea(
            label: 'What is your name?',
            default: "Jess\nJoe"
        );
        $this->assertSame("Jess\nJoe", $result);
    }

    public function testTransformsValues(): void
    {
        Prompt::fake([Key::SPACE, 'J', 'e', 's', 's', Key::SPACE, Key::CTRL_D]);
        $result = textarea(
            label: 'What is your name?',
            transform: fn ($value) => trim($value),
        );
        $this->assertSame('Jess', $result);
    }

    public function testValidates(): void
    {
        Prompt::fake(['J', 'e', 's', Key::CTRL_D, 's', Key::CTRL_D]);
        $result = textarea(
            label: 'What is your name?',
            validate: fn ($value) => $value !== 'Jess' ? 'Invalid name.' : '',
        );
        $this->assertSame('Jess', $result);
        Prompt::assertOutputContains('Invalid name.');
    }

    public function testCancels(): void
    {
        Prompt::fake([Key::CTRL_C]);
        textarea(label: 'What is your name?');
        Prompt::assertOutputContains('Cancelled.');
    }

    public function testBackspaceKeyRemovesCharacter(): void
    {
        Prompt::fake(['J', 'e', 'z', Key::BACKSPACE, 's', 's', Key::CTRL_D]);
        $result = textarea(label: 'What is your name?');
        $this->assertSame('Jess', $result);
    }

    public function testDeleteKeyRemovesCharacter(): void
    {
        Prompt::fake(['J', 'e', 'z', Key::LEFT, Key::DELETE, 's', 's', Key::CTRL_D]);
        $result = textarea(label: 'What is your name?');
        $this->assertSame('Jess', $result);
    }

    public function testCanFallBack(): void
    {
        Prompt::fallbackWhen(true);
        TextareaPrompt::fallbackUsing(function (TextareaPrompt $prompt) {
            $this->assertSame('What is your name?', $prompt->label);
            return 'result';
        });
        $result = textarea('What is your name?');
        $this->assertSame('result', $result);
    }

    public function testSupportsEmacsStyleKeyBindings(): void
    {
        Prompt::fake(['J', 'z', 'e', Key::CTRL_B, Key::CTRL_H, Key::CTRL_F, 's', 's', Key::CTRL_D]);
        $result = textarea(label: 'What is your name?');
        $this->assertSame('Jess', $result);
    }

    public function testMultiLineNavigation(): void
    {
        Prompt::fake([
            'e',
            's',
            's',
            Key::ENTER,
            'o',
            'e',
            Key::UP_ARROW,
            Key::LEFT_ARROW,
            Key::LEFT_ARROW,
            'J',
            Key::DOWN_ARROW,
            Key::LEFT_ARROW,
            'J',
            Key::CTRL_D,
        ]);
        $result = textarea(label: 'What is your name?');
        $this->assertSame("Jess\nJoe", $result);
    }

    public function testHandlesMultiByteStrings(): void
    {
        Prompt::fake([
            'ａ',
            'ｂ',
            Key::ENTER,
            'ｃ',
            'ｄ',
            'ｅ',
            'ｆ',
            Key::ENTER,
            'ｇ',
            'ｈ',
            'ｉ',
            'j',
            'k',
            'l',
            'm',
            'n',
            'n',
            'o',
            'p',
            'q',
            'r',
            's',
            Key::ENTER,
            't',
            'u',
            'v',
            'w',
            'x',
            'y',
            'z',
            Key::UP,
            Key::UP,
            Key::UP,
            Key::UP,
            Key::RIGHT,
            Key::DOWN,
            'y',
            'o',
            Key::CTRL_D,
        ]);

        $result = textarea(label: 'What is your name?');
        $this->assertSame(
            "ａｂ\nｃyoｄｅｆ\nｇｈｉjklmnnopqrs\ntuvwxyz",
            $result
        );
    }

    public function testValidatesDefaultValueWhenNonInteractive(): void
    {
        $this->expectException(NonInteractiveValidationException::class);
        $this->expectExceptionMessage('Required.');

        Prompt::interactive(false);
        textarea('What is your name?', required: true);
    }

    public function testReturnsEmptyStringWhenNonInteractive(): void
    {
        Prompt::interactive(false);
        $result = textarea('What is your name?');
        $this->assertSame('', $result);
    }

    public function testReturnsDefaultValueWhenNonInteractive(): void
    {
        Prompt::interactive(false);
        $result = textarea('What is your name?', default: 'Taylor');
        $this->assertSame('Taylor', $result);
    }
}
