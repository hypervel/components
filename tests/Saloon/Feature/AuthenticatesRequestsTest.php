<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Feature;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Http\Fixtures\LoopbackHttpServer;
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

    // Digest negotiation runs below Saloon, in cURL or Guzzle middleware depending on the Guzzle version.
    // Assert the real exchange instead of upstream's sender option, which middleware can consume.
    public function testYouCanProvideDigestAuthenticationAndGuzzleWillSendIt(): void
    {
        $server = LoopbackHttpServer::start([
            ['status' => 401, 'headers' => ['WWW-Authenticate' => 'Digest realm="saloon", nonce="nonce", qop="auth"']],
            ['body' => 'authenticated'],
        ]);
        $connector = new TestConnector('http://127.0.0.1:' . $server->port);
        $request = new UserRequest;

        $request->withDigestAuth('Sammyjo20', 'Cowboy1');

        $response = $connector->send($request);

        $this->assertSame(200, $response->status());
        $this->assertStringNotContainsString('Authorization:', $server->request());
        $authenticated = $server->request();
        $this->assertStringContainsString('Authorization: Digest ', $authenticated);
        $this->assertStringContainsString('username="Sammyjo20"', $authenticated);
        $this->assertStringContainsString('realm="saloon"', $authenticated);
        $this->assertStringContainsString('nonce="nonce"', $authenticated);
    }
}
