<?php

declare(strict_types=1);

use Hypervel\Http\Client\PendingRequest;
use Hypervel\Support\Facades\Http;

use function PHPStan\Testing\assertType;

foreach (['get', 'post', 'put', 'patch', 'delete', 'head', 'query'] as $method) {
    assertType('Hypervel\Http\Client\Response', Http::createPendingRequest()->{$method}('/foo'));
    assertType('GuzzleHttp\Promise\PromiseInterface', Http::createPendingRequest()->async()->{$method}('/foo'));
}

// PHPStan carries async()'s self-out type onto repeated Http::createPendingRequest()
// expressions in the same scope, although each call creates a fresh request.
// Keep these state checks separate from the loop's inferred async state.
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
