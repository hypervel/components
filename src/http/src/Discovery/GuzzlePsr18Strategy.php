<?php

declare(strict_types=1);

namespace Hypervel\Http\Discovery;

use GuzzleHttp\Client as GuzzleClient;
use Http\Discovery\Strategy\DiscoveryStrategy;
use Psr\Http\Client\ClientInterface;

/**
 * Prefer Guzzle for PSR-18 HTTP client auto-discovery.
 *
 * Symfony's CurlHttpClient uses a shared CurlMultiHandle that is unsafe
 * when reused across Swoole coroutines. Guzzle's PSR-18 sendRequest()
 * forces synchronous mode which routes through CurlHandler (curl_exec),
 * making it coroutine-safe.
 */
class GuzzlePsr18Strategy implements DiscoveryStrategy
{
    /**
     * Return discovery candidates for the given type.
     * @param mixed $type
     */
    public static function getCandidates($type): array
    {
        if ($type === ClientInterface::class) {
            return [['class' => GuzzleClient::class, 'condition' => GuzzleClient::class]];
        }

        return [];
    }
}
