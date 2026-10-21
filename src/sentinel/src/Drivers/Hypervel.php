<?php

declare(strict_types=1);

namespace Hypervel\Sentinel\Drivers;

use Hypervel\Http\Request;
use Hypervel\Support\Str;
use RuntimeException;

class Hypervel extends Driver
{
    /**
     * Authorize access for the request.
     *
     * @throws RuntimeException
     */
    public function authorize(Request $request): bool
    {
        if (! $this->app()->environment('local')) {
            return true;
        }

        if ($this->isPrivateIp($request->ip())
            && ! $request->isFromTrustedProxy()
            && Str::endsWith($request->host(), ['.sharedwithexpose.com', '.ngrok-free.app', '.ngrok.io'])) {
            throw new RuntimeException(
                sprintf('Unable to access "%s /%s" using "local" environment, please change the environment or configure trusted proxies: https://hypervel.org/docs/requests#configuring-trusted-proxies', $request->method(), $request->path())
            );
        }

        // Laravel's driver allows loopback requests here when it detects Docker. A tunnel agent inside the
        // container also connects over loopback, so that exception would expose the dashboard to public clients.
        return $this->authorizeAccessingViaReverseProxies($request);
    }
}
