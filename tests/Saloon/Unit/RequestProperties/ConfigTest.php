<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit\RequestProperties;

use ErrorException;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Traits\RequestProperties\HasOptions;
use Hypervel\Tests\Saloon\Fixtures\Connectors\ConfigConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\ConfigRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;
use Hypervel\Tests\TestCase;

class ConfigTest extends TestCase
{
    public function testDefaultConfigIsMergedInFromARequest(): void
    {
        $request = new ConfigRequest;

        $this->assertSame(['debug' => false], $request->options());
    }

    public function testConfigCanBeManagedOnARequest(): void
    {
        $request = new ConfigRequest;

        $this->assertSame($request, $request->withOptions(['timeout' => 60]));
        $this->assertSame($request, $request->withOptions(['name' => 'Sam', 'category' => 'Cowboy', 'connect_timeout' => 200]));
        $this->assertSame($request, $request->withoutOptions('category'));

        $this->assertEquals([
            'timeout' => 60,
            'name' => 'Sam',
            'connect_timeout' => 200,
            'debug' => false,
        ], $request->options());
        $this->assertSame(60, $request->options()['timeout']);

        // Options have no replace-all method, so upstream's set() becomes removing every option and adding the new one.
        $request->withoutOptions(array_keys($request->options()))->withOptions(['debug' => true]);

        $this->assertSame(['debug' => true], $request->options());
    }

    public function testConfigCanBeManagedOnAConnector(): void
    {
        // Connectors are read-only and may be shared between coroutines, so their default options are managed on
        // the pending request that merges them.
        $connector = new ConfigConnector;
        $pendingRequest = new PendingRequest($connector, new UserRequest);

        $pendingRequest->withOptions(['timeout' => 60]);
        $pendingRequest->withOptions(['name' => 'Sam', 'category' => 'Cowboy', 'connect_timeout' => 200]);
        $pendingRequest->withoutOptions('category');

        $this->assertEquals([
            'timeout' => 60,
            'name' => 'Sam',
            'connect_timeout' => 200,
            'debug' => false,
        ], $pendingRequest->options());
        $this->assertSame(60, $pendingRequest->options()['timeout']);

        $pendingRequest->withoutOptions(array_keys($pendingRequest->options()))->withOptions(['debug' => true]);

        $this->assertSame(['debug' => true], $pendingRequest->options());
        $this->assertSame(['debug' => false], $connector->options());
    }

    public function testOptionsAreMergedRecursively(): void
    {
        $request = (new ConfigRequest)
            ->withOptions(['allow_redirects' => ['max' => 3]])
            ->withOptions(['allow_redirects' => ['strict' => true]]);

        $this->assertSame(['max' => 3, 'strict' => true], $request->options()['allow_redirects']);
    }

    public function testMaxRedirectsPreservesArraySettings(): void
    {
        $request = $this->request()
            ->withOptions(['allow_redirects' => ['strict' => true]])
            ->maxRedirects(3);

        $this->assertSame(['strict' => true, 'max' => 3], $request->options()['allow_redirects']);
    }

    public function testMaxRedirectsReenablesRedirectsWithoutDeprecation(): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        }, E_DEPRECATED);

        try {
            $request = $this->request()
                ->withoutRedirecting()
                ->maxRedirects(3);

            $this->assertSame(['max' => 3], $request->options()['allow_redirects']);
        } finally {
            restore_error_handler();
        }
    }

    public function testWithoutRedirectingDisablesAConfiguredRedirectLimit(): void
    {
        $request = $this->request()
            ->maxRedirects(3)
            ->withoutRedirecting();

        $this->assertFalse($request->options()['allow_redirects']);
    }

    public function testMaxRedirectsReplacesTheBooleanEnabledForm(): void
    {
        $request = $this->request()
            ->withOptions(['allow_redirects' => true])
            ->maxRedirects(3);

        $this->assertSame(['max' => 3], $request->options()['allow_redirects']);
    }

    public function testWithoutVerifyingReplacesACertificateBundle(): void
    {
        $request = $this->request()
            ->withOptions(['verify' => '/etc/ssl/certs/api.pem'])
            ->withoutVerifying();

        $this->assertFalse($request->options()['verify']);
    }

    /**
     * Create an object with operation-owned request options.
     */
    protected function request(): object
    {
        return new class {
            use HasOptions;
        };
    }
}
