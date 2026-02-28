<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Console\PromptsValidationTest;

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

        $this->app[Kernel::class]->registerCommand(new ClosureValidationCommand());
        $this->app[Kernel::class]->registerCommand(new LaravelRulesCommand());
        $this->app[Kernel::class]->registerCommand(new MethodMessagesCommand());
        $this->app[Kernel::class]->registerCommand(new InlineMessagesCommand());
    }

    public function testValidationForPrompts(): void
    {
        $this
            ->artisan(ClosureValidationCommand::class)
            ->expectsQuestion('What is your name?', '')
            ->expectsOutputToContain('Required!');
    }

    public function testValidationWithLaravelRulesAndNoCustomization(): void
    {
        $this
            ->artisan(LaravelRulesCommand::class)
            ->expectsQuestion('What is your name?', '')
            ->expectsOutputToContain('The answer field is required.');
    }

    public function testValidationWithLaravelRulesInlineMessagesAndAttributes(): void
    {
        $this
            ->artisan(InlineMessagesCommand::class)
            ->expectsQuestion('What is your name?', '')
            ->expectsOutputToContain('Your full name is mandatory.');
    }

    public function testValidationWithLaravelRulesMessagesAndAttributes(): void
    {
        $this
            ->artisan(MethodMessagesCommand::class)
            ->expectsQuestion('What is your name?', '')
            ->expectsOutputToContain('Your full name is mandatory.');
    }
}

class ClosureValidationCommand extends Command
{
    protected ?string $signature = 'prompts-validation-test';

    public function handle(): void
    {
        text('What is your name?', validate: fn ($value) => $value === '' ? 'Required!' : null);
    }
}

class LaravelRulesCommand extends Command
{
    protected ?string $signature = 'prompts-laravel-rules-test';

    public function handle(): void
    {
        text('What is your name?', validate: 'required');
    }
}

class InlineMessagesCommand extends Command
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

class MethodMessagesCommand extends Command
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
