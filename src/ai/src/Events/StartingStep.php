<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Gateway\TextGenerationOptions;
use Hypervel\Ai\Messages\Message;
use Hypervel\Ai\Providers\Tools\ProviderTool;

class StartingStep
{
    /**
     * Create an event for a generation step being started.
     *
     * @param string $model the model the step is requested against, which the responding model reported on the completed step may differ from
     * @param Message[] $messages the messages being sent for this step, including the tool results of the steps before it
     * @param null|TextGenerationOptions $options the options resolved for this step, which may differ from the agent's own once a forced tool choice has been satisfied
     * @param array<ProviderTool|Tool> $tools the tools resolved for this step after middleware
     */
    public function __construct(
        public string $invocationId,
        public int $stepNumber,
        public Agent $agent,
        public TextProvider $provider,
        public string $model,
        public bool $isFinalStep,
        public array $messages,
        public ?TextGenerationOptions $options,
        public ?string $instructions = null,
        public array $tools = [],
    ) {
    }

    /**
     * Prepare the step for serialization without its live tool instances.
     */
    public function __serialize(): array
    {
        // Resolved tools may contain closures or anonymous classes that cannot be queued.
        return [...get_mangled_object_vars($this), 'tools' => []];
    }
}
