<?php

namespace FacadesL1241;

use Hypervel\Support\Facades\Cache;
use Hypervel\Support\Facades\Http;

use function PHPStan\Testing\assertType;

function test(): void
{
    assertType('Hypervel\Http\Client\Response', Http::get('https://example.test'));
    assertType('Hypervel\Http\Client\Response', Http::post('https://example.test'));
    assertType('Hypervel\Http\Client\Response', Http::put('https://example.test'));
    assertType('Hypervel\Http\Client\Response', Http::patch('https://example.test'));
    assertType('Hypervel\Http\Client\Response', Http::delete('https://example.test'));
    assertType('Hypervel\Http\Client\Response', Http::head('https://example.test'));
    assertType('Hypervel\Http\Client\Response', Http::send('GET', 'https://example.test'));
    assertType('Hypervel\Http\Client\Response', Http::timeout(30)->get('https://example.test'));
    assertType('Hypervel\Http\Client\Response', Http::withHeaders(['X-Foo' => 'bar'])->post('https://example.test'));

    assertType('int', Http::get('https://example.test')->getStatusCode());

    // Hypervel: async requests return Guzzle's promise. Laravel's LazyPromise serves only the
    // omitted pool() and batch() concurrency.
    assertType('GuzzleHttp\Promise\PromiseInterface', Http::async()->get('https://example.test'));
    assertType('GuzzleHttp\Promise\PromiseInterface', Http::async()->post('https://example.test'));
    assertType('GuzzleHttp\Promise\PromiseInterface', Http::async()->send('GET', 'https://example.test'));
    assertType('GuzzleHttp\Promise\PromiseInterface', Http::timeout(30)->async()->get('https://example.test'));
    assertType('GuzzleHttp\Promise\PromiseInterface', Http::withHeaders(['X-Foo' => 'bar'])->async()->post('https://example.test'));

    assertType('Hypervel\Contracts\Cache\Repository', Cache::driver());
    assertType('\'123\'', Cache::remember(
        key: 'cache-key',
        ttl: 60,
        callback: static fn (): string => '123',
    ));
    assertType('123', Cache::rememberForever(
        key: 'cache-key',
        callback: static fn (): int => 123,
    ));
}
