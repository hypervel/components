<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\Xai\Concerns;

use Hypervel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Hypervel\Http\Client\RequestException;

trait HandlesXaiErrors
{
    use HandlesFailoverErrors;

    /**
     * Determine if xAI rejected the request for a missing or invalid API key.
     */
    protected function isInvalidApiKey(RequestException $exception): bool
    {
        return match ($exception->response->status()) {
            401 => true,
            400 => is_string($error = $exception->response->json('error', flags: 0)) && str_starts_with($error, 'Incorrect API key'),
            default => false,
        };
    }
}
