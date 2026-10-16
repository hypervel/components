<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\AzureOpenAi;

use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Gateway\AzureOpenAi\Concerns\CreatesAzureOpenAiClient;
use Hypervel\Ai\Gateway\OpenAi\OpenAiFileGateway;
use Override;

class AzureOpenAiFileGateway extends OpenAiFileGateway
{
    use CreatesAzureOpenAiClient;

    /**
     * Get the default purpose to use when a file does not specify one.
     */
    #[Override]
    protected function defaultPurpose(): string
    {
        return 'assistants';
    }

    /**
     * Get the provider key used to resolve file upload options.
     */
    #[Override]
    protected function providerOptionsKey(): Lab
    {
        return Lab::Azure;
    }
}
