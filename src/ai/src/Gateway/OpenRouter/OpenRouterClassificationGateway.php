<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\OpenRouter;

use Hypervel\Ai\Contracts\Gateway\ClassificationGateway;
use Hypervel\Ai\Gateway\Concerns\AnswersQuestions;
use Hypervel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Hypervel\Ai\Gateway\OpenRouter\Concerns\CreatesOpenRouterClient;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Support\Str;

class OpenRouterClassificationGateway implements ClassificationGateway
{
    use AnswersQuestions;
    use CreatesOpenRouterClient {
        baseUrl as openRouterBaseUrl;
    }
    use HandlesFailoverErrors;

    /**
     * Get the path of the endpoint that answers questions.
     */
    protected function classificationEndpoint(): string
    {
        return '/alpha/decisions';
    }

    /**
     * Get the base URL for the Decisions API, which is served outside of the versioned API.
     */
    protected function baseUrl(Provider $provider): string
    {
        return Str::chopEnd($this->openRouterBaseUrl($provider), '/v1');
    }
}
