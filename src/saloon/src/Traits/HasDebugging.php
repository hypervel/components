<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits;

use Hypervel\Saloon\Enums\PipeOrder;
use Hypervel\Saloon\Http\Debugger;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

trait HasDebugging
{
    /**
     * Debug the final application-owned request.
     *
     * @param null|callable(PendingRequest, RequestInterface): void $onRequest
     * @return $this
     */
    public function debugRequest(?callable $onRequest = null, bool $die = false): static
    {
        $onRequest ??= Debugger::dumpRequest(...);

        $this->middleware()->onRequest(
            static function (PendingRequest $pendingRequest) use ($onRequest, $die): void {
                $pendingRequest->observePsrRequest(
                    static function (RequestInterface $request, PendingRequest $pendingRequest) use ($onRequest, $die): void {
                        $onRequest($pendingRequest, $request);

                        if ($die) {
                            Debugger::terminate();
                        }
                    },
                );
            },
            order: PipeOrder::Last,
        );

        return $this;
    }

    /**
     * Debug the response before ordinary response middleware.
     *
     * @param null|callable(Response, ResponseInterface): void $onResponse
     * @return $this
     */
    public function debugResponse(?callable $onResponse = null, bool $die = false): static
    {
        $onResponse ??= Debugger::dumpResponse(...);

        $this->middleware()->onResponse(
            static function (Response $response) use ($onResponse, $die): Response {
                if (! $response->stream()->isSeekable()) {
                    $response->body();
                }

                $onResponse($response, $response->toPsrResponse());

                if ($die) {
                    Debugger::terminate();
                }

                return $response;
            },
            order: PipeOrder::First,
        );

        return $this;
    }

    /**
     * Debug both the request and response.
     *
     * Raw debugging output may contain credentials or other sensitive values.
     *
     * @return $this
     */
    public function debug(bool $die = false): static
    {
        return $this->debugRequest()->debugResponse(die: $die);
    }
}
