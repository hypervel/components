<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Integrations;

use Hypervel\Context\Context;
use Psr\Http\Message\ServerRequestInterface;
use Sentry\Integration\RequestFetcherInterface;

class RequestFetcher implements RequestFetcherInterface
{
    public function fetchRequest(): ?ServerRequestInterface
    {
        /** @var null|ServerRequestInterface $request */
        $request = Context::get(ServerRequestInterface::class);

        if (! $request || ! method_exists($request, 'withServerParams')) {
            return $request;
        }

        return $request->withServerParams(
            array_merge(
                $request->getServerParams(),
                array_change_key_case($request->getServerParams(), CASE_UPPER)
            )
        );
    }
}
