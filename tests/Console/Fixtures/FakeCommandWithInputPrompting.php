<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Fixtures;

use Hypervel\Console\Command;
use Hypervel\Contracts\Console\PromptsForMissingInput;
use Hypervel\Prompts\Prompt;
use Hypervel\Prompts\TextPrompt;
use Hypervel\Support\Json;
use Symfony\Component\Console\Input\InputInterface;

class FakeCommandWithInputPrompting extends Command implements PromptsForMissingInput
{
    protected ?string $signature = 'fake-command-for-testing {name : An argument}';

    public bool $prompted = false;

    /**
     * Configure the prompt fallback for missing input.
     */
    protected function configurePrompts(InputInterface $input): void
    {
        Prompt::interactive(true);
        Prompt::fallbackWhen(true);

        TextPrompt::fallbackUsing(function () {
            $this->prompted = true;

            return 'foo';
        });
    }

    /**
     * Report the prompt result from the executed command instance.
     */
    public function handle(): int
    {
        $this->line(Json::encode(['prompted' => $this->prompted, 'name' => $this->argument('name')]));

        return self::SUCCESS;
    }
}
