<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\DeepSeek;

use Hypervel\Ai\Contracts\Gateway\StepTextGateway;
use Hypervel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Hypervel\Ai\Gateway\Concerns\ParsesServerSentEvents;
use Hypervel\Ai\Gateway\OpenAiCompatible\Concerns\MapsChatCompletionTools;
use Hypervel\Ai\Gateway\OpenAiCompatible\Concerns\PerformsChatCompletionSteps;
use Hypervel\Contracts\Events\Dispatcher;

class DeepSeekGateway implements StepTextGateway
{
    use Concerns\BuildsTextRequests;
    use Concerns\CreatesDeepSeekClient;
    use Concerns\HandlesTextStreaming;
    use Concerns\MapsAttachments;
    use Concerns\MapsMessages;
    use Concerns\ParsesTextResponses;
    use HandlesFailoverErrors;
    use MapsChatCompletionTools;
    use ParsesServerSentEvents;
    use PerformsChatCompletionSteps;

    /**
     * Create a DeepSeek gateway.
     */
    public function __construct(protected Dispatcher $events)
    {
    }
}
