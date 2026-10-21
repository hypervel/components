<?php

declare(strict_types=1);

namespace Hypervel\Ai\Console\Commands;

use Hypervel\Ai\Responses\AgentResponse;
use Hypervel\Ai\Responses\StructuredAgentResponse;
use Hypervel\Console\Attributes\Description;
use Hypervel\Console\Attributes\Signature;
use Hypervel\Console\Command;

use function Hypervel\Ai\agent;
use function Hypervel\Prompts\note;
use function Hypervel\Prompts\spin;
use function Hypervel\Prompts\textarea;

#[Description('Chat with one of your agents')]
#[Signature('agent:chat')]
class ChatCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $agent = agent(
            instructions: 'You are a helpful assistant.',
        );

        while (true) { // @phpstan-ignore while.alwaysTrue (intentional interactive loop)
            $prompt = textarea('Prompt...');

            $response = spin(
                fn (): AgentResponse => $agent->prompt($prompt),
                message: 'Thinking...',
            );

            if ($response instanceof StructuredAgentResponse) {
                $response = json_encode($response->structured, JSON_PRETTY_PRINT);
            }

            note($response . PHP_EOL);
        }
    }
}
