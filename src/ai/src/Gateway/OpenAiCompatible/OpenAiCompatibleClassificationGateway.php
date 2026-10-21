<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\OpenAiCompatible;

use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Gateway\OpenAi\OpenAiClassificationGateway;
use Hypervel\Ai\Gateway\OpenAiCompatible\Concerns\CreatesOpenAiCompatibleClient;
use Override;

class OpenAiCompatibleClassificationGateway extends OpenAiClassificationGateway
{
    use CreatesOpenAiCompatibleClient;

    /**
     * Get the provider key used to resolve image attachment options.
     */
    #[Override]
    protected function providerOptionsKey(): Lab
    {
        return Lab::OpenAICompatible;
    }
}
