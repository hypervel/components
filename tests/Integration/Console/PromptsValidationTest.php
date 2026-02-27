<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Console;

use Hypervel\Console\Command;
use Hypervel\Contracts\Console\Kernel;
use Hypervel\Testbench\TestCase;

use function Hypervel\Prompts\text;

/**
 * @internal
 * @coversNothing
 */
class PromptsValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app[Kernel::class]->registerCommand(new DummyPromptsValidationCommand());
        $this->app[Kernel::class]->registerCommand(new DummyPromptsWithLaravelRulesCommand());
        $this->app[Kernel::class]->registerCommand(new DummyPromptsWithLaravelRulesMessagesAndAttributesCommand());
        $this->app[Kernel::class]->registerCommand(new DummyPromptsWithLaravelRulesCommandWithInlineMessagesAndAttributesCommand());
    }

    public function testValidationForPrompts(): void
    {
        $this
            ->artisan(DummyPromptsValidationCommand::class)
            ->expectsQuestion('What is your name?', '')
            ->expectsOutputToContain('Required!');
    }

    public function testValidationWithLaravelRulesAndNoCustomization(): void
    {
        $this
            ->artisan(DummyPromptsWithLaravelRulesCommand::class)
            ->expectsQuestion('What is your name?', '')
            ->expectsOutputToContain('The answer field is required.');
    }

    public function testValidationWithLaravelRulesInlineMessagesAndAttributes(): void
    {
        $this
            ->artisan(DummyPromptsWithLaravelRulesCommandWithInlineMessagesAndAttributesCommand::class)
            ->expectsQuestion('What is your name?', '')
            ->expectsOutputToContain('Your full name is mandatory.');
    }

    public function testValidationWithLaravelRulesMessagesAndAttributes(): void
    {
        $this
            ->artisan(DummyPromptsWithLaravelRulesMessagesAndAttributesCommand::class)
            ->expectsQuestion('What is your name?', '')
            ->expectsOutputToContain('Your full name is mandatory.');
    }
}

class DummyPromptsValidationCommand extends Command
{
    protected ?string $signature = 'prompts-validation-test';

    public function handle(): void
    {
        text('What is your name?', validate: fn ($value) => $value === '' ? 'Required!' : null);
    }
}

class DummyPromptsWithLaravelRulesCommand extends Command
{
    protected ?string $signature = 'prompts-laravel-rules-test';

    public function handle(): void
    {
        text('What is your name?', validate: 'required');
    }
}

class DummyPromptsWithLaravelRulesCommandWithInlineMessagesAndAttributesCommand extends Command
{
    protected ?string $signature = 'prompts-laravel-rules-inline-test';

    public function handle(): void
    {
        text('What is your name?', validate: literal(
            rules: ['name' => 'required'],
            messages: ['name.required' => 'Your :attribute is mandatory.'],
            attributes: ['name' => 'full name'],
        ));
    }
}

class DummyPromptsWithLaravelRulesMessagesAndAttributesCommand extends Command
{
    protected ?string $signature = 'prompts-laravel-rules-messages-attributes-test';

    public function handle(): void
    {
        text('What is your name?', validate: ['name' => 'required']);
    }

    protected function validationMessages(): array
    {
        return ['name.required' => 'Your :attribute is mandatory.'];
    }

    protected function validationAttributes(): array
    {
        return ['name' => 'full name'];
    }
}
