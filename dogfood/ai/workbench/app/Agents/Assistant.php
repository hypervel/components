<?php

declare(strict_types=1);

namespace Workbench\App\Agents;

use Hypervel\Ai\Concerns\RemembersConversations;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\Conversational;
use Hypervel\Ai\Contracts\HasTools;
use Hypervel\Ai\Promptable;
use Workbench\App\Tools\ConvertTemperature;
use Workbench\App\Tools\CurrentLocation;
use Workbench\App\Tools\SendEmail;
use Workbench\App\Tools\Weather;

class Assistant implements Agent, Conversational, HasTools
{
    use Promptable;
    use RemembersConversations;

    /**
     * Get the tools available to the agent.
     */
    public function tools(): array
    {
        return [
            new CurrentLocation,
            new Weather,
            new ConvertTemperature,
            (new SendEmail)->requireApproval('Sending email on your behalf.'),
        ];
    }

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return 'You are a helpful assistant used to manually test the Hypervel AI SDK. '
            . 'Use the available tools for locations, weather, temperature conversions, and email instead of answering from memory. '
            . 'Keep replies short.';
    }
}
