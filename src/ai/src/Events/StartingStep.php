<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Gateway\TextGenerationOptions;
use Hypervel\Ai\Messages\Message;

class StartingStep
{
    /**
     * Create an event for a generation step being started.
     *
     * @param string $model the model the step is requested against, which the responding model reported on the completed step may differ from
     * @param Message[] $messages the messages being sent for this step, including the tool results of the steps before it
     * @param null|TextGenerationOptions $options the options resolved for this step, which may differ from the agent's own once a forced tool choice has been satisfied
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
    ) {
    }
}
