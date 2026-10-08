<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Files;

use Hypervel\Ai\Files\RemoteImage;
use Hypervel\Ai\Files\UntrustedUrl;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Pool\PoolManager;
use Hypervel\Http\Client\Destinations\DestinationResolutionException;
use Hypervel\Http\Client\Destinations\DisallowedDestinationException;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Http\Fixtures\LoopbackHttpServer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Swoole\Coroutine\CanceledException;

class UntrustedUrlTest extends TestCase
{
    /**
     * Resolve fake download hosts without external DNS.
     */
    protected function setUp(): void
    {
        parent::setUp();

        UntrustedUrl::resolveUsing(static fn (): array => ['93.184.216.34']);
    }

    /**
     * Configure a single database pool slot for the DNS release test.
     */
    protected function defineDatabaseEnvironment(Application $app): void
    {
        $app->make('config')->set('database.connections.downloads', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
            'pool' => [
                'testing_enabled' => true,
                'min_retained_connections' => 1,
                'max_connections' => 1,
                'wait_timeout' => 1.0,
            ],
        ]);
    }

    #[DataProvider('blockedUrls')]
    public function testARemoteFilePointingAtABlockedAddressIsNeverFetched(string $url): void
    {
        Http::fake();
        UntrustedUrl::resolveUsing(static fn (): array => ['127.0.0.1']);

        try {
            (new RemoteImage($url))->content();
            $this->fail('The blocked destination was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertInstanceOf(DisallowedDestinationException::class, $exception->getPrevious());
        }

        Http::assertNothingSent();
    }

    /**
     * Provide disallowed remote destinations.
     */
    public static function blockedUrls(): array
    {
        return [
            'metadata endpoint' => ['http://169.254.169.254/latest/meta-data/'],
            'loopback' => ['http://127.0.0.1:8000/secret'],
            'private range' => ['http://10.0.0.5/admin'],
            'cgnat range' => ['http://100.64.1.1/'],
            'localhost' => ['http://localhost/'],
            'local suffix' => ['http://printer.local/'],
            'trailing dot' => ['http://localhost./'],
            'ipv6 loopback' => ['http://[::1]/'],
            'ipv4 mapped ipv6' => ['http://[::ffff:10.0.0.1]/'],
            'nat64 embedded ipv4' => ['http://[64:ff9b::a00:1]/'],
            'local-use nat64' => ['http://[64:ff9b:1::a00:1]/'],
            'unique local ipv6' => ['http://[fd00::1]/'],
            'unsupported scheme' => ['ftp://example.com/file'],
            'embedded credentials' => ['https://user:password@example.com/file'],
        ];
    }

    public function testAHostnameResolvingToAPrivateAddressIsBlocked(): void
    {
        Http::fake();
        UntrustedUrl::resolveUsing(static fn (): array => ['93.184.216.34', '169.254.169.254']);

        try {
            (new RemoteImage('https://rebinding.example.com/photo.png'))->content();
            $this->fail('The blocked destination was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('disallowed address', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function testAnUnresolvableHostnameThrowsAnInvalidArgumentException(): void
    {
        Http::fake();
        UntrustedUrl::resolveUsing(static fn (): array => []);

        try {
            (new RemoteImage('https://missing.example.com/photo.png'))->content();
            $this->fail('The unresolvable destination was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertInstanceOf(DestinationResolutionException::class, $exception->getPrevious());
        }

        Http::assertNothingSent();
    }

    #[DefineEnvironment('defineDatabaseEnvironment')]
    public function testIdleDatabaseSessionsAreReleasedBeforeResolvingTheHost(): void
    {
        DB::connection('downloads')->selectOne('select 1');
        $pool = $this->app->make(PoolManager::class)->pool('downloads');
        $this->assertSame(0, $pool->getIdleCount());

        UntrustedUrl::resolveUsing(function () use ($pool): array {
            $this->assertSame(1, $pool->getIdleCount());

            return ['93.184.216.34'];
        });
        Http::fake(['example.com/*' => Http::response('bytes')]);

        $this->assertSame('bytes', (new RemoteImage('https://example.com/photo.png'))->content());
    }

    #[DataProvider('downloadUrls')]
    public function testTheConnectionIsPinnedToTheValidatedAddresses(string $url, string $normalized): void
    {
        $resolved = [];
        UntrustedUrl::resolveUsing(static function (string $host) use (&$resolved): array {
            $resolved[] = $host;

            return ['93.184.216.34', '2606:4700:4700::1111'];
        });
        Http::fake(function (Request $request, array $options) use ($normalized) {
            $this->assertSame($normalized, $request->url());
            $this->assertSame(['example.com:443:[2606:4700:4700::1111],93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);

            return Http::response('bytes');
        });

        $this->assertSame('bytes', (new RemoteImage($url))->content());
        $this->assertSame(['example.com'], $resolved);
    }

    /**
     * Provide ordinary, normalized, and encoded download URLs.
     */
    public static function downloadUrls(): array
    {
        return [
            'signed query' => ['https://example.com/photo.png?token=signed', 'https://example.com/photo.png?token=signed'],
            'trailing dot' => ['https://EXAMPLE.COM./photo.png', 'https://example.com/photo.png'],
            'encoded path and query' => ['https://example.com/résumé 1.pdf?q=日本', 'https://example.com/r%C3%A9sum%C3%A9%201.pdf?q=%E6%97%A5%E6%9C%AC'],
        ];
    }

    #[DataProvider('publicLiterals')]
    public function testAPublicIpLiteralIsFetchedWithoutPinning(string $url): void
    {
        UntrustedUrl::resolveUsing(function (): array {
            $this->fail('An IP literal must not be resolved through DNS.');
        });
        Http::fake(function (Request $request, array $options) {
            $this->assertArrayNotHasKey('curl', $options);

            return Http::response('bytes');
        });

        $this->assertSame('bytes', (new RemoteImage($url))->content());
    }

    /**
     * Provide public address literals.
     */
    public static function publicLiterals(): array
    {
        return [
            'ipv4' => ['http://93.184.216.34/photo.png'],
            'nat64 embedded public ipv4' => ['http://[64:ff9b::808:808]/photo.png'],
        ];
    }

    public function testAnAllowedHostSkipsThePrivateAddressCheck(): void
    {
        config(['ai.remote_files.allowed_hosts' => ['minio', '::1']]);
        UntrustedUrl::resolveUsing(function (): array {
            $this->fail('A trusted host must not require local DNS.');
        });
        Http::fake(function (Request $request, array $options) {
            $this->assertArrayNotHasKey('curl', $options);

            return Http::response('bytes');
        });

        $this->assertSame('bytes', (new RemoteImage('http://MINIO.:9000/bucket/photo.png'))->content());
        $this->assertSame('bytes', (new RemoteImage('http://[::1]/photo.png'))->content());
    }

    #[DataProvider('invalidTrustedUrls')]
    public function testTrustedHostsStillRequireHttpWithoutCredentials(string $url): void
    {
        config(['ai.remote_files.allowed_hosts' => ['minio']]);
        Http::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTP or HTTPS without embedded credentials');

        UntrustedUrl::fetch($url);
    }

    /**
     * Provide invalid URLs for an explicitly trusted host.
     */
    public static function invalidTrustedUrls(): array
    {
        return [
            ['http://user:password@minio/photo.png'],
            ['ftp://minio/photo.png'],
        ];
    }

    #[DataProvider('blockedRedirectOrigins')]
    public function testARedirectToABlockedAddressIsNotFollowed(string $host): void
    {
        config(['ai.remote_files.allowed_hosts' => ['minio']]);
        Http::fake([
            "{$host}/*" => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
        ]);

        try {
            (new RemoteImage("https://{$host}/photo.png"))->content();
            $this->fail('The redirect to a blocked destination was followed.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('disallowed address', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    /**
     * Provide public and explicitly trusted redirect origins.
     */
    public static function blockedRedirectOrigins(): array
    {
        return [
            'public host' => ['example.com'],
            'trusted host' => ['minio'],
        ];
    }

    public function testARedirectToAPublicAddressIsFollowed(): void
    {
        Http::fake([
            'example.com/photo.png' => Http::response('', 301, ['Location' => '/moved/photo.png']),
            'example.com/moved/*' => Http::response('bytes', 200),
        ]);

        $this->assertSame('bytes', (new RemoteImage('https://example.com/photo.png'))->content());

        Http::assertSentCount(2);
    }

    public function testTooManyRedirectsThrows(): void
    {
        Http::fake(['example.com/*' => Http::response('', 302, ['Location' => 'https://example.com/again'])]);

        try {
            (new RemoteImage('https://example.com/photo.png'))->content();
            $this->fail('The redirect limit was not enforced.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('redirected too many times', $exception->getMessage());
        }

        Http::assertSentCount(6);
    }

    public function testDownloadsInheritTheGlobalProxyConfiguration(): void
    {
        $proxy = LoopbackHttpServer::start([['body' => 'bytes']]);
        Http::globalOptions(['proxy' => "http://127.0.0.1:{$proxy->port}"]);

        $this->assertSame('bytes', (new RemoteImage('http://files.invalid/photo.png'))->content());
        $this->assertStringStartsWith('GET http://files.invalid/photo.png HTTP/1.1', $proxy->request());
    }

    #[DataProvider('downloadSizes')]
    public function testTheDecodedDownloadSizeIsBounded(int $limit, string $content, bool $compressed, bool $rejected): void
    {
        config(['ai.remote_files.allowed_hosts' => ['127.0.0.1'], 'ai.remote_files.max_size' => $limit]);
        $server = LoopbackHttpServer::start([[
            'body' => $compressed ? gzencode($content) : $content,
            'headers' => $compressed ? ['Content-Encoding' => 'gzip'] : [],
        ]]);

        if ($rejected) {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage("maximum size of [{$limit}] bytes");
        }

        $this->assertSame($content, (new RemoteImage("http://127.0.0.1:{$server->port}/photo.png"))->content());
    }

    /**
     * Provide exact, excessive, and compressed downloads.
     */
    public static function downloadSizes(): array
    {
        return [
            'exact limit' => [5, 'bytes', false, false],
            'one byte over' => [4, 'bytes', false, true],
            'decoded gzip' => [128, str_repeat('x', 1024), true, true],
        ];
    }

    public function testANullSizeLimitAllowsDownloadsLargerThanTheDefault(): void
    {
        config(['ai.remote_files.allowed_hosts' => ['127.0.0.1'], 'ai.remote_files.max_size' => null]);
        $size = UntrustedUrl::DEFAULT_MAX_SIZE + 1;
        $server = LoopbackHttpServer::start([[
            'body' => gzencode(str_repeat('x', $size)),
            'headers' => ['Content-Encoding' => 'gzip'],
        ]]);

        $content = (new RemoteImage("http://127.0.0.1:{$server->port}/photo.png"))->content();

        $this->assertSame($size, strlen($content));
    }

    #[DataProvider('invalidSizeLimits')]
    public function testTheSizeLimitMustBeAPositiveIntegerOrNull(int|bool $limit): void
    {
        config(['ai.remote_files.max_size' => $limit]);
        Http::fake(['*' => Http::response('bytes')]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a positive integer or null');

        UntrustedUrl::fetch('https://example.com/photo.png');
    }

    /**
     * Provide invalid download limits.
     */
    public static function invalidSizeLimits(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'wrong type' => [false],
        ];
    }

    public function testFakedDownloadsEnforceTheSizeLimit(): void
    {
        config(['ai.remote_files.max_size' => 4]);
        Http::fake(['*' => Http::response('bytes')]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('maximum size of [4] bytes');

        (new RemoteImage('https://example.com/photo.png'))->content();
    }

    public function testCancellationDuringTransferCompletionTakesPrecedenceOverTheSizeFailure(): void
    {
        config(['ai.remote_files.allowed_hosts' => ['127.0.0.1'], 'ai.remote_files.max_size' => 4]);
        $server = LoopbackHttpServer::start([['body' => 'bytes']]);
        $canceled = new CanceledException('Canceled while publishing transfer statistics.');
        Http::globalOptions(['on_stats' => static function () use ($canceled): void {
            throw $canceled;
        }]);

        try {
            UntrustedUrl::fetch("http://127.0.0.1:{$server->port}/photo.png");
            $this->fail('The cancellation was swallowed.');
        } catch (CanceledException $exception) {
            $this->assertSame($canceled, $exception);
        }
    }
}
