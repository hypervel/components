<?php

namespace Facades;

use App\DummyFacade;
use Hypervel\Support\Facades\Cache;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Facades\Log;
use Hypervel\Support\Facades\Queue;
use Hypervel\Support\Facades\Redis;
use Hypervel\Support\Facades\Request;
use Hypervel\Support\Facades\Storage;
use Hypervel\Tests\PHPStan\Fixtures\Facades\UndocumentedFacade;

use function PHPStan\dumpType;
use function PHPStan\Testing\assertType;

function test(): void
{
    assertType('Hypervel\Http\Request', Request::instance());

    assertType('null', Event::assertDispatched('FooEvent'));
    assertType('null', Event::assertDispatchedTimes('FooEvent', 5));
    assertType('null', Event::assertNotDispatched('FooEvent'));

    // Hypervel: a pooled connection may hold a Redis Cluster client.
    $redis = Redis::connection();
    assertType('array|Redis|RedisCluster|false', $redis->lrange('some-key', 0, -1));
    assertType('array|Redis|RedisCluster|false', Redis::lrange('some-key', 0, -1));
    assertType('bool|Redis|RedisCluster', Redis::expire('foo', 3));

    assertType('Hypervel\Database\Query\Builder', DB::query());
    assertType('int', DB::transactionLevel());

    assertType('null', Queue::createPayloadUsing(function () {
    }));

    assertType('Psr\Log\LoggerInterface', Log::getLogger());

    // Hypervel: each disk is typed from the booted configuration, where the default disk is local.
    assertType('Hypervel\Filesystem\LocalFilesystemAdapter', Storage::disk());
    assertType('Hypervel\Filesystem\LocalFilesystemAdapter', Storage::drive());
    // REMOVED: Storage::cloud() cases; Hypervel omits the default-cloud shortcut.
    assertType('bool', Storage::disk()->deleteDirectory('foo'));
    assertType('bool', Storage::drive()->deleteDirectory('foo'));
    assertType('string|false', Storage::putFile('foo', 'foo/bar'));
    // Hypervel: a static return forwarded through the facade describes its root, not the facade.
    assertType('Hypervel\Filesystem\FilesystemManager', Storage::extend('custom', static fn (): null => null));
    // REMOVED: Redis::client(); pooled Redis requires a held connection, through Redis::withConnection().

    assertType('string', DummyFacade::foo());
    assertType('int', DummyFacade::bar());
    // Hypervel: a facade without @method annotations takes its root's method from the booted application.
    assertType('string', UndocumentedFacade::greet());
    // Hypervel: the facade calls its root from outside, so a non-public method is not one of its methods.
    assertType('*ERROR*', UndocumentedFacade::whisper());

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
