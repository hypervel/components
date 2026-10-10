<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\AzureOpenAi;

use Hypervel\Ai\Gateway\AzureOpenAi\Concerns\CreatesAzureOpenAiClient;
use Hypervel\Ai\Gateway\OpenAi\OpenAiStoreGateway;

class AzureOpenAiStoreGateway extends OpenAiStoreGateway
{
    use CreatesAzureOpenAiClient;
}
