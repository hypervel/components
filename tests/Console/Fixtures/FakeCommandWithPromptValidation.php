<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Fixtures;

use Hypervel\Console\Command;

use function Hypervel\Prompts\text;

class FakeCommandWithPromptValidation extends Command
{
    protected ?string $signature = 'fake-prompt-validation-test';

    public function handle(): int
    {
        text('What is your name?', validate: fn (string $value) => $value === '' ? 'Required!' : null);

        return self::SUCCESS;
    }
}
