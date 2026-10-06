<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\ConfirmPrompt;
use Hypervel\Prompts\Exceptions\NonInteractiveValidationException;
use Hypervel\Prompts\Key;
use Hypervel\Prompts\Prompt;
use Hypervel\Tests\TestCase;

use function Hypervel\Prompts\confirm;

class ConfirmPromptTest extends TestCase
{
    public function testConfirm(): void
    {
        Prompt::fake([Key::ENTER]);

        $result = confirm(label: 'Are you sure?');

        $this->assertTrue($result);
    }

    public function testArrowKeysChangeTheValue(): void
    {
        Prompt::fake([Key::DOWN, Key::ENTER]);

        $result = confirm(label: 'Are you sure?');

        $this->assertFalse($result);
    }

    public function testTheYSelectsYes(): void
    {
        Prompt::fake(['y', Key::ENTER]);

        $result = confirm(label: 'Are you sure?');

        $this->assertTrue($result);
    }

    public function testTheNSelectsNo(): void
    {
        Prompt::fake(['n', Key::ENTER]);

        $result = confirm(label: 'Are you sure?');

        $this->assertFalse($result);
    }

    public function testAcceptsADefaultValue(): void
    {
        Prompt::fake([Key::ENTER]);

        $result = confirm(
            label: 'Are you sure?',
            default: false
        );

        $this->assertFalse($result);
    }

    public function testAllowsTheLabelsToBeChanged(): void
    {
        Prompt::fake([Key::ENTER]);

        $result = confirm(
            label: '¿Estás seguro?',
            yes: 'Sí, por favor',
            no: 'No, gracias'
        );

        $this->assertTrue($result);

        Prompt::assertOutputContains('Sí, por favor');
        Prompt::assertOutputContains('No, gracias');
    }

    public function testTransformsValues(): void
    {
        Prompt::fake([Key::ENTER]);

        $result = confirm(
            label: 'Are you sure?',
            transform: fn ($value) => ! $value,
        );

        $this->assertFalse($result);
    }

    public function testValidates(): void
    {
        Prompt::fake([Key::ENTER, 'y', Key::ENTER]);

        $result = confirm(
            label: 'Would you like to continue?',
            default: false,
            validate: fn ($value) => $value === false ? 'You must choose yes.' : null,
        );

        $this->assertTrue($result);

        Prompt::assertOutputContains('You must choose yes.');
    }

    public function testSupportEmacsStyleKeyBinding(): void
    {
        Prompt::fake([Key::CTRL_N, Key::ENTER]);

        $result = confirm(label: 'Are you sure?');

        $this->assertFalse($result);
    }

    public function testReturnsTheDefaultValueWhenNonInteractive(): void
    {
        Prompt::interactive(false);

        $result = confirm('Would you like to continue?', false);

        $this->assertFalse($result);
    }

    public function testValidatesTheDefaultValueWhenNonInteractive(): void
    {
        Prompt::interactive(false);

        $this->expectException(NonInteractiveValidationException::class);
        $this->expectExceptionMessageIs('Required.');

        confirm(
            'Would you like to continue?',
            default: false,
            required: true,
        );
    }

    public function testSupportsCustomValidation(): void
    {
        Prompt::validateUsing(function (Prompt $prompt): ?string {
            $this->assertSame('Are you sure?', $prompt->label);
            $this->assertSame('confirmed', $prompt->validate);

            return $prompt->validate === 'confirmed' && ! $prompt->value() ? 'Need to be sure!' : null;
        });

        Prompt::fake([Key::DOWN, Key::ENTER, Key::UP, Key::ENTER]);

        confirm(label: 'Are you sure?', validate: 'confirmed');

        Prompt::assertOutputContains('Need to be sure!');

        Prompt::validateUsing(fn () => null);
    }

    public function testCanFallBack(): void
    {
        Prompt::fallbackWhen(true);

        ConfirmPrompt::fallbackUsing(function (ConfirmPrompt $prompt): bool {
            $this->assertSame('Would you like to continue?', $prompt->label);

            return true;
        });

        $result = confirm('Would you like to continue?', false);

        $this->assertTrue($result);

        Prompt::fallbackWhen(false);
        ConfirmPrompt::fallbackUsing(fn () => false);
    }
}
