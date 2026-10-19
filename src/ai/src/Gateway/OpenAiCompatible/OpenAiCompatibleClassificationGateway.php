<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\OpenAiCompatible;

use Hypervel\Ai\Gateway\OpenAi\OpenAiClassificationGateway;
use Hypervel\Ai\Gateway\OpenAiCompatible\Concerns\CreatesOpenAiCompatibleClient;

class OpenAiCompatibleClassificationGateway extends OpenAiClassificationGateway
{
    use CreatesOpenAiCompatibleClient;
}
