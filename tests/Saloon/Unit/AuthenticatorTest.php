<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Saloon\Exceptions\MissingAuthenticatorException;
use Hypervel\Saloon\Http\Auth\TokenAuthenticator;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Authenticators\PizzaAuthenticator;
use Hypervel\Tests\Saloon\Fixtures\Connectors\DefaultAuthenticatorConnector;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\AuthenticatorPluginRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\BootAuthenticatorRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\DefaultAuthenticatorRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\DefaultPizzaAuthenticatorRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\RequiresAuthRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;

class AuthenticatorTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    public function testYouCanAddAnAuthenticatorToARequestAndItWillBeApplied(): void
    {
        $request = new DefaultAuthenticatorRequest;
        $pendingRequest = (new TestConnector)->createPendingRequest($request);

        $this->assertSame('Bearer yee-haw-request', $pendingRequest->headers()['Authorization']);
    }

    public function testYouCanProvideADefaultAuthenticatorOnTheConnector(): void
    {
        $request = new UserRequest;
        $connector = new DefaultAuthenticatorConnector;

        $pendingRequest = $connector->createPendingRequest($request);

        $this->assertSame('Bearer yee-haw-connector', $pendingRequest->headers()['Authorization']);
    }

    public function testYouCanProvideADefaultAuthenticatorOnTheRequestAndItTakesPriorityOverTheConnector(): void
    {
        $request = new DefaultAuthenticatorRequest;
        $connector = new DefaultAuthenticatorConnector;

        $pendingRequest = $connector->createPendingRequest($request);

        $this->assertSame('Bearer yee-haw-request', $pendingRequest->headers()['Authorization']);
    }

    public function testYouCanProvideAnAuthenticatorOnTheFlyAndItWillTakePriorityOverAllDefaults(): void
    {
        $request = new DefaultAuthenticatorRequest;
        $connector = new DefaultAuthenticatorConnector;

        $request->withToken('yee-haw-on-the-fly', 'PewPew');

        $pendingRequest = $connector->createPendingRequest($request);

        $this->assertSame('PewPew yee-haw-on-the-fly', $pendingRequest->headers()['Authorization']);
    }

    public function testTheRequiresAuthTraitWillThrowAnExceptionIfAnAuthenticatorIsNotFound(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(),
        ]);

        $this->expectException(MissingAuthenticatorException::class);
        $this->expectExceptionMessageIs('The "Hypervel\Tests\Saloon\Fixtures\Requests\RequiresAuthRequest" request requires authentication.');

        $request = new RequiresAuthRequest;

        (new TestConnector)->send($request, $mockClient);
    }

    public function testYouCanUseYourOwnAuthenticators(): void
    {
        $request = new UserRequest;
        $request->authenticate(new PizzaAuthenticator('Margherita', 'San Pellegrino'));

        $pendingRequest = (new TestConnector)->createPendingRequest($request);

        $headers = $pendingRequest->headers();

        $this->assertSame('Margherita', $headers['X-Pizza']);
        $this->assertSame('San Pellegrino', $headers['X-Drink']);
        $this->assertTrue($pendingRequest->options()['debug']);
    }

    public function testYouCanUseYourOwnAuthenticatorsAsDefault(): void
    {
        $request = new DefaultPizzaAuthenticatorRequest;

        $pendingRequest = (new TestConnector)->createPendingRequest($request);

        $headers = $pendingRequest->headers();

        $this->assertSame('BBQ Chicken', $headers['X-Pizza']);
        $this->assertSame('Lemonade', $headers['X-Drink']);
        $this->assertTrue($pendingRequest->options()['debug']);
    }

    public function testYouCanCustomiseTheAuthenticatorInsideOfTheBootMethod(): void
    {
        $request = new BootAuthenticatorRequest;

        $this->assertNull($request->authenticator());

        $pendingRequest = (new TestConnector)->createPendingRequest($request);

        $this->assertEquals(new TokenAuthenticator('howdy-partner'), $pendingRequest->authenticator());
        $this->assertSame('Bearer howdy-partner', $pendingRequest->headers()['Authorization']);
    }

    public function testYouCanCustomiseTheAuthenticatorInsideOfPlugins(): void
    {
        $request = new AuthenticatorPluginRequest;

        $this->assertNull($request->authenticator());

        $pendingRequest = (new TestConnector)->createPendingRequest($request);

        $this->assertEquals(new TokenAuthenticator('plugin-auth'), $pendingRequest->authenticator());
        $this->assertSame('Bearer plugin-auth', $pendingRequest->headers()['Authorization']);
    }

    public function testYouCanCustomiseTheAuthenticatorInsideOfAMiddlewarePipeline(): void
    {
        $request = new UserRequest;

        $this->assertNull($request->authenticator());

        $request->middleware()
            ->onRequest(function (PendingRequest $pendingRequest): void {
                $pendingRequest->withToken('ooh-this-is-cool');
            });

        $pendingRequest = (new TestConnector)->createPendingRequest($request);

        $this->assertEquals(new TokenAuthenticator('ooh-this-is-cool'), $pendingRequest->authenticator());
        $this->assertSame('Bearer ooh-this-is-cool', $pendingRequest->headers()['Authorization']);
    }

    public function testYouCanAddAnAuthenticatorInsideOfRequestMiddleware(): void
    {
        $request = new UserRequest;

        // The returned pending request is ignored; authenticate() applies to the one the middleware receives.
        $request->middleware()->onRequest(function (PendingRequest $pendingRequest): PendingRequest {
            return $pendingRequest->withToken('yee-haw-request');
        });

        $pendingRequest = (new TestConnector)->createPendingRequest($request);

        $this->assertSame('Bearer yee-haw-request', $pendingRequest->headers()['Authorization']);
    }

    public function testIfYouUseTheAuthenticateMethodOnAFullyConstructedPendingRequestItWillAuthenticateRightAway(): void
    {
        $connector = new TestConnector;
        $pendingRequest = $connector->createPendingRequest(new UserRequest);

        $this->assertSame([
            'Accept' => 'application/json',
        ], $pendingRequest->headers());

        $pendingRequest->authenticate(new TokenAuthenticator('yee-haw-request'));

        $this->assertSame([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer yee-haw-request',
        ], $pendingRequest->headers());
    }
}
