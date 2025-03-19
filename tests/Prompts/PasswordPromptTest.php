<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Exceptions\NonInteractiveValidationException;
use Hypervel\Prompts\Key;
use Hypervel\Prompts\PasswordPrompt;
use Hypervel\Prompts\Prompt;
use PHPUnit\Framework\TestCase;

use function Hypervel\Prompts\password;

/**
 * @backupStaticProperties enabled
 * @internal
 * @coversNothing
 */
class PasswordPromptTest extends TestCase
{
    public function testReturnsTheInput(): void
    {
        Prompt::fake(['p', 'a', 's', 's', Key::ENTER]);
        $result = password(label: 'What is the password?');
        $this->assertSame('pass', $result);
    }

    public function testTransformsValues(): void
    {
        Prompt::fake(['p', 'a', 's', 's', 'w', 'o', 'r', 'd', Key::ENTER]);
        $dontUseInProduction = md5('password');

        $result = password(
            label: 'What is the password?',
            transform: fn ($value) => md5($value)
        );

        $this->assertSame($dontUseInProduction, $result);
    }

    public function testValidates(): void
    {
        Prompt::fake(['p', 'a', 's', Key::ENTER, 's', Key::ENTER]);

        $result = password(
            label: 'What is the password',
            validate: fn ($value) => strlen($value) < 4 ? 'Password must be at least 4 characters.' : '',
        );

        $this->assertSame('pass', $result);
        Prompt::assertOutputContains('Password must be at least 4 characters.');
    }

    public function testCancels(): void
    {
        Prompt::fake([Key::CTRL_C]);
        password(label: 'What is the password');
        Prompt::assertOutputContains('Cancelled.');
    }

    public function testBackspaceKeyRemovesCharacter(): void
    {
        Prompt::fake(['p', 'a', 'z', Key::BACKSPACE, 's', 's', Key::ENTER]);
        $result = password(label: 'What is the password?');
        $this->assertSame('pass', $result);
    }

    public function testDeleteKeyRemovesCharacter(): void
    {
        Prompt::fake(['p', 'a', 'z', Key::LEFT, Key::DELETE, 's', 's', Key::ENTER]);
        $result = password(label: 'What is the password?');
        $this->assertSame('pass', $result);
    }

    public function testCanFallBack(): void
    {
        Prompt::fallbackWhen(true);

        PasswordPrompt::fallbackUsing(function (PasswordPrompt $prompt) {
            $this->assertSame('What is the password?', $prompt->label);
            return 'result';
        });

        $result = password('What is the password?');
        $this->assertSame('result', $result);
    }

    public function testReturnsEmptyStringWhenNonInteractive(): void
    {
        Prompt::interactive(false);
        $result = password('What is the password?');
        $this->assertSame('', $result);
    }

    public function testFailsValidationWhenNonInteractive(): void
    {
        $this->expectException(NonInteractiveValidationException::class);
        $this->expectExceptionMessage('Required.');

        Prompt::interactive(false);
        password('What is the password?', required: true);
    }

    public function testSupportsCustomValidation(): void
    {
        Prompt::validateUsing(function (Prompt $prompt) {
            $this->assertSame('What is the password?', $prompt->label);
            $this->assertSame('min:8', $prompt->validate);
            return $prompt->validate === 'min:8' && strlen($prompt->value()) < 8 ? 'Minimum 8 chars!' : null;
        });

        Prompt::fake(['p', Key::ENTER, 'a', 's', 's', 'w', 'o', 'r', 'd', Key::ENTER]);

        $result = password(
            label: 'What is the password?',
            validate: 'min:8',
        );

        $this->assertSame('password', $result);
        Prompt::assertOutputContains('Minimum 8 chars!');

        Prompt::validateUsing(fn () => null);
    }
}
