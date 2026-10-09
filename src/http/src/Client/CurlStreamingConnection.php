<?php

declare(strict_types=1);

namespace Hypervel\Http\Client;

use CurlMultiHandle;

/**
 * Retain one native connection cache without retaining a request or its callbacks.
 *
 * @internal
 */
class CurlStreamingConnection
{
    public CurlMultiHandle $handle;

    /**
     * Create an isolated transport with bounded native idle retention.
     */
    public function __construct(
        public readonly string $route,
        public readonly ?string $proxyTunnelSignature = null,
    ) {
        $this->handle = curl_multi_init();
        curl_multi_setopt($this->handle, CURLMOPT_MAXCONNECTS, 1);
    }
}
