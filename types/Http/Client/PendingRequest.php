<?php

declare(strict_types=1);

use Hypervel\Http\Client\PendingRequest;
use Hypervel\Http\Client\Response;
use Hypervel\Support\Facades\Http;

use function PHPStan\Testing\assertType;

foreach (['get', 'post', 'put', 'patch', 'delete', 'head', 'query'] as $method) {
    assertType('Hypervel\Http\Client\Response', Http::createPendingRequest()->{$method}('/foo'));
    assertType('GuzzleHttp\Promise\PromiseInterface', Http::createPendingRequest()->async()->{$method}('/foo'));
}

// PHPStan carries async()'s self-out type onto repeated Http::createPendingRequest()
// expressions in the same scope, although each call creates a fresh request.
// Keep the state and callback checks below in separate scopes from that loop.
function (bool $async): void {
    assertType('Hypervel\Http\Client\Response', Http::createPendingRequest()->withHeaders([])->get('/foo'));
    assertType('GuzzleHttp\Promise\PromiseInterface|Hypervel\Http\Client\Response', Http::async()->get('/foo'));

    $request = Http::createPendingRequest();
    assertType('Hypervel\Http\Client\Response', $request->send('GET', '/foo'));
    $request->async();
    assertType('GuzzleHttp\Promise\PromiseInterface', $request->send('GET', '/foo'));
    $request->async(false);
    assertType('Hypervel\Http\Client\Response', $request->get('/foo'));

    assertType('GuzzleHttp\Promise\PromiseInterface|Hypervel\Http\Client\Response', Http::createPendingRequest()->async($async)->get('/foo'));
};

function (): void {
    $request = Http::createPendingRequest()
        ->afterResponse(function ($response, $request): string {
            assertType('Hypervel\Http\Client\Response', $response);
            assertType('Hypervel\Http\Client\Request|null', $request);

            return 'ignored';
        })
        ->afterResponse(static function (Response $response): void {
        })
        ->afterResponse(static fn (Response $response): Response => new Response($response->toPsrResponse()));

    assertType('Hypervel\Http\Client\PendingRequest<false>', $request);
    assertType('Hypervel\Http\Client\Response', $request->get('/foo'));
};

class PlainHttpPendingRequest extends PendingRequest
{
}

assertType('GuzzleHttp\Promise\PromiseInterface|Hypervel\Http\Client\Response', (new PlainHttpPendingRequest)->async()->get('/foo'));

/**
 * @template TAsync of bool = bool
 * @extends PendingRequest<TAsync>
 */
class GenericHttpPendingRequest extends PendingRequest
{
}

$genericRequest = (new GenericHttpPendingRequest)->async();
assertType('GenericHttpPendingRequest<true>', $genericRequest);
assertType('GuzzleHttp\Promise\PromiseInterface', $genericRequest->get('/foo'));
