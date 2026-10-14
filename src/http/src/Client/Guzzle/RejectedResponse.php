<?php

declare(strict_types=1);

namespace Hypervel\Http\Client\Guzzle;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ResponseException;
use Psr\Http\Message\ResponseInterface;

class RejectedResponse
{
    /**
     * Get the PSR-7 response carried by a transfer's rejection reason, if any.
     */
    public static function from(mixed $reason): ?ResponseInterface
    {
        // Guzzle 8 uses ResponseException.
        if ($reason instanceof ResponseException) {
            return $reason->getResponse();
        }

        // Guzzle 7 uses RequestException with hasResponse() true.
        if ($reason instanceof RequestException && is_callable([$reason, 'hasResponse']) && $reason->hasResponse()) {
            return $reason->getResponse(); // @phpstan-ignore method.notFound (Only Guzzle 7 enters this branch; its RequestException also declares getResponse.)
        }

        return null;
    }
}
