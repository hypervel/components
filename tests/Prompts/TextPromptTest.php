<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Exception;
use Hypervel\Prompts\Exceptions\NonInteractiveValidationException;
use Hypervel\Prompts\Key;
use Hypervel\Prompts\Prompt;
use Hypervel\Prompts\TextPrompt;
use PHPUnit\Framework\TestCase;

use function Hypervel\Prompts\text;

/**
 * @backupStaticProperties enabled
 * @internal
 * @coversNothing
 */
class TextPromptTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        Prompt::cancelUsing(null);
    }

    public function testReturnsTheInput(): void
    {
        Prompt::fake(['J', 'e', 's', 's', Key::ENTER]);
        $result = text(label: 'What is your name?');
        $this->assertSame('Jess', $result);
    }

    public function testAcceptsDefaultValue(): void
    {
        Prompt::fake([Key::ENTER]);
        $result = text(
            label: 'What is your name?',
            default: 'Jess'
        );
        $this->assertSame('Jess', $result);
    }

    public function testTransformsValues(): void
    {
        Prompt::fake([Key::SPACE, 'J', 'e', 's', 's', Key::TAB, Key::ENTER]);
        $result = text(
            label: 'What is your name?',
            transform: fn ($value) => trim($value),
        );
        $this->assertSame('Jess', $result);
    }

    public function testValidates(): void
    {
        Prompt::fake(['J', 'e', 's', Key::ENTER, 's', Key::ENTER]);
        $result = text(
            label: 'What is your name?',
            validate: fn ($value) => $value !== 'Jess' ? 'Invalid name.' : '',
        );
        $this->assertSame('Jess', $result);
        Prompt::assertOutputContains('Invalid name.');
    }

    public function testCancels(): void
    {
        Prompt::fake([Key::CTRL_C]);
        text(label: 'What is your name?');
        Prompt::assertOutputContains('Cancelled.');
    }

    public function testBackspaceKeyRemovesCharacter(): void
    {
        Prompt::fake(['J', 'e', 'z', Key::BACKSPACE, 's', 's', Key::ENTER]);
        $result = text(label: 'What is your name?');
        $this->assertSame('Jess', $result);
    }

    public function testDeleteKeyRemovesCharacter(): void
    {
        Prompt::fake(['J', 'e', 'z', Key::LEFT, Key::DELETE, 's', 's', Key::ENTER]);
        $result = text(label: 'What is your name?');
        $this->assertSame('Jess', $result);
    }

    public function testCanFallBack(): void
    {
        Prompt::fallbackWhen(true);
        TextPrompt::fallbackUsing(function (TextPrompt $prompt) {
            $this->assertSame('What is your name?', $prompt->label);
            return 'result';
        });
        $result = text('What is your name?');
        $this->assertSame('result', $result);
    }

    public function testSupportsEmacsStyleKeyBinding(): void
    {
        Prompt::fake(['J', 'z', 'e', Key::CTRL_B, Key::CTRL_H, Key::CTRL_F, 's', 's', Key::ENTER]);
        $result = text(label: 'What is your name?');
        $this->assertSame('Jess', $result);
    }

    public function testMoveToBeginningAndEndOfLine(): void
    {
        Prompt::fake(['A', 'r', Key::HOME[0], 's', KEY::END[0], 'c', Key::HOME[1], 's', Key::END[1], 'h', Key::HOME[2], 'e', Key::END[2], 'e', Key::HOME[3], 'J', Key::END[3], 'r', Key::ENTER]);
        $result = text(label: 'What is your name?');
        $this->assertSame('JessArcher', $result);
    }

    public function testReturnsEmptyStringWhenNonInteractive(): void
    {
        Prompt::interactive(false);
        $result = text('What is your name?');
        $this->assertSame('', $result);
    }

    public function testReturnsDefaultValueWhenNonInteractive(): void
    {
        Prompt::interactive(false);
        $result = text('What is your name?', default: 'Taylor');
        $this->assertSame('Taylor', $result);
    }

    public function testValidatesDefaultValueWhenNonInteractive(): void
    {
        $this->expectException(NonInteractiveValidationException::class);
        $this->expectExceptionMessage('Required.');

        Prompt::interactive(false);
        text('What is your name?', required: true);
    }

    public function testSupportsCustomValidation(): void
    {
        Prompt::validateUsing(function (Prompt $prompt) {
            $this->assertSame('What is your name?', $prompt->label);
            $this->assertSame('min:2', $prompt->validate);
            return $prompt->validate === 'min:2' && strlen($prompt->value()) < 2 ? 'Minimum 2 chars!' : null;
        });

        Prompt::fake(['J', Key::ENTER, 'e', 's', 's', Key::ENTER]);

        $result = text(
            label: 'What is your name?',
            validate: 'min:2',
        );

        $this->assertSame('Jess', $result);
        Prompt::assertOutputContains('Minimum 2 chars!');

        Prompt::validateUsing(fn () => null);
    }

    public function testAllowsCustomizingCancellation(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Cancelled.');

        Prompt::cancelUsing(fn () => throw new Exception('Cancelled.'));
        Prompt::fake([Key::CTRL_C]);
        text('What is your name?');
    }

    public function testHandlesFailedTerminalReadGracefully(): void
    {
        Prompt::fake(['', Key::ENTER]);
        $result = text('What is your name?');
        $this->assertSame('', $result);
    }
}
