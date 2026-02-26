<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Fixtures;

use Hypervel\Console\Command;
use Hypervel\Contracts\Console\PromptsForMissingInput;
use Hypervel\Prompts\Prompt;
use Hypervel\Prompts\TextPrompt;
use Symfony\Component\Console\Input\InputInterface;

class FakeCommandWithArrayInputPrompting extends Command implements PromptsForMissingInput
{
    protected ?string $signature = 'fake-command-for-testing-array {names* : An array argument}';

    public bool $prompted = false;

    protected function configurePrompts(InputInterface $input): void
    {
        Prompt::interactive(true);
        Prompt::fallbackWhen(true);

        TextPrompt::fallbackUsing(function () {
            $this->prompted = true;

            return 'foo';
        });
    }

    public function handle(): int
    {
        return self::SUCCESS;
    }
}
