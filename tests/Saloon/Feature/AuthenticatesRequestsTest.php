<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Feature;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Http\Client\Factory;
use Hypervel\Http\Client\Request as HttpRequest;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;

class AuthenticatesRequestsTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    // Upstream inspects the auth option in Guzzle middleware added through its sender. Guzzle's auth middleware
    // consumes that option before the transport, so this answers its unauthenticated probe with a digest challenge
    // and asserts the credentials Guzzle sends in reply.
    public function testYouCanProvideDigestAuthenticationAndGuzzleWillSendIt(): void
    {
        $connector = new TestConnector;
        $request = new UserRequest;

        $request->withDigestAuth('Sammyjo20', 'Cowboy1');

        $authorization = [];

        Http::fake(function (HttpRequest $request) use (&$authorization): PromiseInterface {
            $authorization[] = $request->header('Authorization');

            return count($authorization) === 1
                ? Factory::response('', 401, ['WWW-Authenticate' => 'Digest realm="saloon", nonce="nonce", qop="auth"'])
                : Factory::response();
        });

        $response = $connector->send($request);

        $this->assertSame(200, $response->status());
        $this->assertCount(2, $authorization);
        $this->assertSame([], $authorization[0]);
        $this->assertStringStartsWith('Digest username="Sammyjo20", realm="saloon", nonce="nonce"', $authorization[1][0]);
    }
}
