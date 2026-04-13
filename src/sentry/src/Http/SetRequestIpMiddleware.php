<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Http;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Http\Request;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enrich the Sentry scope with the IP address of the request.
 *
 * We do this instead of letting the PHP SDK handle it because we want
 * the IP from the Hypervel request which takes trusted proxies into account.
 */
class SetRequestIpMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $container = Container::getInstance();

        if ($container->bound(HubInterface::class)) {
            $sentry = $container->make(HubInterface::class);

            $client = $sentry->getClient();

            if ($client !== null && $client->getOptions()->shouldSendDefaultPii()) {
                $sentry->configureScope(static function (Scope $scope) use ($request): void {
                    $scope->setUser([
                        'ip_address' => $request->ip(),
                    ]);
                });
            }
        }

        return $next($request);
    }
}
