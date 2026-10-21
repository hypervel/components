<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Feature\Body;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Http\Client\Request as HttpRequest;
use Hypervel\Saloon\Contracts\Body\BodyRepository;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Exceptions\PendingRequestException;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Repositories\Body\JsonBodyRepository;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Saloon\Traits\Body\HasJsonBody;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\HasJsonBodyConnector;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\HasJsonBodyRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\HasMultipartBodyRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;

class HasJsonBodyTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    public function testTheDefaultBodyIsLoadedWithTheContentTypeHeader(): void
    {
        $request = new HasJsonBodyRequest;

        $this->assertSame([
            'name' => 'Sam',
            'catchphrase' => 'Yeehaw!',
        ], $request->body());

        $pendingRequest = $this->pendingRequest(new TestConnector, $request);

        $this->assertSame('application/json', $pendingRequest->headers()['Content-Type']);
    }

    public function testTheContentTypeHeaderIsSetInThePendingRequest(): void
    {
        $pendingRequest = $this->pendingRequest(TestConnector::make(), new HasJsonBodyRequest);

        $this->assertSame([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ], $pendingRequest->headers());
    }

    public function testAnExplicitContentTypeOverridesTheBodyTraitDefault(): void
    {
        $request = (new HasJsonBodyRequest)->withHeader('Content-Type', 'application/vnd.api+json');

        $pendingRequest = $this->pendingRequest(new TestConnector, $request);

        $this->assertSame('application/vnd.api+json', $pendingRequest->headers()['Content-Type']);
    }

    public function testWhenJustTheConnectorHasBodyTheBodyWillBeSent(): void
    {
        // Connectors are read-only and expose no body, so their default body is checked on the pending request.
        $pendingRequest = $this->pendingRequest(new HasJsonBodyConnector, new UserRequest);

        $this->assertSame([
            'name' => 'Gareth',
            'drink' => 'Moonshine',
        ], $pendingRequest->body());
        $this->assertSame('{"name":"Gareth","drink":"Moonshine"}', (string) $pendingRequest->preparedBody());
    }

    public function testWhenBothTheConnectorAndTheRequestHaveTheSameRequestBodiesTheyWillBeMerged(): void
    {
        $request = new HasJsonBodyRequest;

        $this->assertSame([
            'name' => 'Sam',
            'catchphrase' => 'Yeehaw!',
        ], $request->body());

        // Name should be overwritten to "Sam" and "catchphrase" should be merged in
        $pendingRequest = $this->pendingRequest(new HasJsonBodyConnector, $request);

        $this->assertSame([
            'name' => 'Sam',
            'drink' => 'Moonshine',
            'catchphrase' => 'Yeehaw!',
        ], $pendingRequest->body());
        $this->assertSame('{"name":"Sam","drink":"Moonshine","catchphrase":"Yeehaw!"}', (string) $pendingRequest->preparedBody());
    }

    public function testIfTheConnectorAndRequestImplementDifferentBodyRepositoriesThenAnExceptionIsThrown(): void
    {
        $this->expectException(PendingRequestException::class);
        $this->expectExceptionMessageIs('Connector and request body types must be the same.');

        $this->pendingRequest(new HasJsonBodyConnector, new HasMultipartBodyRequest);
    }

    public function testTheGuzzleSenderProperlySendsIt(): void
    {
        $connector = new TestConnector;
        $request = new HasJsonBodyRequest;

        $request->middleware()->onRequest(function (PendingRequest $pendingRequest): void {
            $this->assertSame('application/json', $pendingRequest->headers()['Content-Type']);
        });

        $asserted = false;

        Http::fake(function (HttpRequest $httpRequest) use (&$asserted): PromiseInterface {
            $this->assertSame(['application/json'], $httpRequest->header('Content-Type'));
            $this->assertSame('{"name":"Sam","catchphrase":"Yeehaw!"}', $httpRequest->body());

            $asserted = true;

            return Http::response();
        });

        $connector->send($request);

        $this->assertTrue($asserted);
    }

    public function testYouCanSpecifyDifferentJsonFlagsThatTheBodyRepositoryShouldUse(): void
    {
        // Request bodies are not exposed as repositories, so the flags are tested on the repository itself and then
        // supplied through a request's defaultBodyRepository().
        $body = new JsonBodyRepository((new HasJsonBodyRequest)->body());

        // We'll add a property with slashes
        $body->add('url', 'https://docs.saloon.dev');

        // By default, PHP will escape slashes
        $this->assertSame('{"name":"Sam","catchphrase":"Yeehaw!","url":"https:\/\/docs.saloon.dev"}', (string) $body);

        // Now we'll customise the flags
        $body->setJsonFlags(JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $this->assertSame('{"name":"Sam","catchphrase":"Yeehaw!","url":"https://docs.saloon.dev"}', (string) $body);
        $this->assertSame(JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR, $body->getJsonFlags());

        $pendingRequest = $this->pendingRequest(new TestConnector, new UnescapedSlashesJsonRequestStub);

        $this->assertSame('{"url":"https://docs.saloon.dev"}', (string) $pendingRequest->preparedBody());
    }

    public function testTheJsonBodyRepositoryUsesTheJsonThrowOnErrorDefaultFlag(): void
    {
        $this->assertSame(JSON_THROW_ON_ERROR, (new JsonBodyRepository)->getJsonFlags());
    }

    /**
     * Send the request with a mock response and return its pending request.
     */
    protected function pendingRequest(Connector $connector, Request $request): PendingRequest
    {
        return $connector->send($request, new MockClient([MockResponse::make()]))->pendingRequest();
    }
}

class UnescapedSlashesJsonRequestStub extends Request
{
    use HasJsonBody;

    /**
     * Define the method that the request will use.
     */
    protected Method $method = Method::POST;

    /**
     * Define the endpoint for the request.
     */
    public function resolveEndpoint(): string
    {
        return '/user';
    }

    /**
     * Define the default body.
     *
     * @return array<string, string>
     */
    protected function defaultBody(): array
    {
        return ['url' => 'https://docs.saloon.dev'];
    }

    /**
     * Resolve the default body repository.
     */
    protected function defaultBodyRepository(): BodyRepository
    {
        return (new JsonBodyRepository($this->defaultBody()))
            ->setJsonFlags(JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
