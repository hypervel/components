<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Feature\Body;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Http\Client\Request as HttpRequest;
use Hypervel\Saloon\Contracts\Body\BodyRepository;
use Hypervel\Saloon\Data\MultipartValue;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Repositories\Body\MultipartBodyRepository;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Stringable;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\HasMultipartBodyConnector;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\HasMultipartBodyRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;
use PHPUnit\Framework\Attributes\DataProvider;

class HasMultipartBodyTest extends TestCase
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
        $request = new HasMultipartBodyRequest;

        $this->assertEquals([
            new MultipartValue('nickname', 'Sam', 'user.txt', ['X-Saloon' => 'Yee-haw!']),
        ], $request->body());

        $pendingRequest = $this->pendingRequest(new TestConnector, $request);
        $contentType = $pendingRequest->headers()['Content-Type'];
        $boundary = substr($contentType, strlen('multipart/form-data; boundary='));

        $this->assertStringStartsWith('multipart/form-data; boundary=', $contentType);
        $this->assertStringStartsWith('--' . $boundary . "\r\n", (string) $pendingRequest->preparedBody());
    }

    public function testWhenBothTheConnectorAndTheRequestHaveTheSameRequestBodiesTheyWillBeMerged(): void
    {
        $request = new HasMultipartBodyRequest;

        $this->assertEquals([
            new MultipartValue('nickname', 'Sam', 'user.txt', ['X-Saloon' => 'Yee-haw!']),
        ], $request->body());

        // Connectors are read-only and expose no body, so their default body is checked on the pending request.
        $pendingRequest = $this->pendingRequest(new HasMultipartBodyConnector, $request);

        $this->assertEquals([
            new MultipartValue('nickname', 'Gareth', 'user.txt', ['X-Saloon' => 'Yee-haw!']),
            new MultipartValue('drink', 'Moonshine', 'moonshine.txt', ['X-My-Head' => 'Spinning!']),
            new MultipartValue('nickname', 'Sam', 'user.txt', ['X-Saloon' => 'Yee-haw!']),
        ], $pendingRequest->body());

        $contentType = $pendingRequest->headers()['Content-Type'];
        $body = (string) $pendingRequest->preparedBody();

        $this->assertStringStartsWith('multipart/form-data; boundary=', $contentType);
        $this->assertStringStartsWith('--' . substr($contentType, strlen('multipart/form-data; boundary=')) . "\r\n", $body);
        $this->assertStringContainsString("\r\n\r\nGareth\r\n", $body);
        $this->assertStringContainsString("\r\n\r\nMoonshine\r\n", $body);
        $this->assertStringContainsString("\r\n\r\nSam\r\n", $body);
    }

    public function testTheGuzzleSenderProperlySendsIt(): void
    {
        $connector = new TestConnector;
        $request = new HasMultipartBodyRequest;

        $asserted = false;

        // The boundary content type is added when the body is prepared, after request middleware runs, so it always
        // matches the final body. The upstream middleware assertion is therefore made on the sent request.
        Http::fake(function (HttpRequest $httpRequest) use (&$asserted): PromiseInterface {
            $contentType = $httpRequest->header('Content-Type')[0];
            $body = $httpRequest->body();

            $this->assertStringStartsWith('multipart/form-data; boundary=', $contentType);
            $this->assertStringStartsWith('--' . substr($contentType, strlen('multipart/form-data; boundary=')), $body);
            $this->assertStringContainsString('X-Saloon: Yee-haw!', $body);
            $this->assertStringContainsString('Content-Disposition: form-data; name="nickname"; filename="user.txt"', $body);
            $this->assertStringContainsString('Sam', $body);

            $asserted = true;

            return Http::response();
        });

        $connector->send($request);

        $this->assertTrue($asserted);
    }

    // The real-server cases "can send a real multipart request and files are sent" and "can send an empty string as
    // the contents" are in tests/Integration/Saloon/Feature/Body/HasMultipartBodyTest.php.

    public function testCanSendMultipleMultipartFilesWithTheSameKeyName(): void
    {
        $connector = new TestConnector;
        $request = new HasMultipartBodyRequest;

        $request->attach('nickname', 'Alfie', 'user.txt');
        $request->attach('nickname', 'Tom', 'user.txt');

        $asserted = false;

        Http::fake(function (HttpRequest $httpRequest) use (&$asserted): PromiseInterface {
            $body = $httpRequest->body();

            $this->assertStringContainsString('X-Saloon: Yee-haw!', $body);
            $this->assertStringContainsString('Content-Disposition: form-data; name="nickname"; filename="user.txt"', $body);
            $this->assertStringContainsString('Sam', $body);
            $this->assertStringContainsString('Alfie', $body);
            $this->assertStringContainsString('Tom', $body);

            $asserted = true;

            return Http::response();
        });

        $connector->send($request);

        $this->assertTrue($asserted);
    }

    public function testAMultipartBodyReplacesAContentTypeWithoutABoundary(): void
    {
        $request = (new UserRequest)
            ->withHeader('content-type', 'application/json')
            ->attach('nickname', 'Sam');
        $snapshotContentType = null;

        // Request middleware runs before the body is prepared, so its PSR snapshot resolves the content type itself.
        $request->middleware()->onRequest(function (PendingRequest $pendingRequest) use (&$snapshotContentType): void {
            $snapshotContentType = $pendingRequest->toPsrRequest()->getHeader('Content-Type');
        });

        $pendingRequest = $this->pendingRequest(new TestConnector, $request);
        $contentType = $pendingRequest->headers()['Content-Type'];

        $this->assertStringStartsWith('multipart/form-data; boundary=', $contentType);
        $this->assertSame([$contentType], $snapshotContentType);
        $this->assertStringStartsWith(
            '--' . substr($contentType, strlen('multipart/form-data; boundary=')) . "\r\n",
            (string) $pendingRequest->preparedBody(),
        );
    }

    #[DataProvider('contentTypesWithABoundary')]
    public function testAContentTypeThatDeclaresABoundaryIsKept(mixed $contentType): void
    {
        $request = (new ExplicitBoundaryMultipartRequestStub)->withHeader('Content-Type', $contentType);
        $snapshotContentType = null;

        $request->middleware()->onRequest(function (PendingRequest $pendingRequest) use (&$snapshotContentType): void {
            $snapshotContentType = $pendingRequest->toPsrRequest()->getHeaderLine('Content-Type');
        });

        $pendingRequest = $this->pendingRequest(new TestConnector, $request);

        $this->assertSame('multipart/related; boundary=saloon-boundary', $snapshotContentType);
        $this->assertSame('multipart/related; boundary=saloon-boundary', $pendingRequest->toPsrRequest()->getHeaderLine('Content-Type'));
    }

    /**
     * Get the supported representations of a content type that declares a boundary.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function contentTypesWithABoundary(): iterable
    {
        yield 'string' => ['multipart/related; boundary=saloon-boundary'];
        yield 'list' => [['multipart/related; boundary=saloon-boundary']];
        yield 'stringable' => [new Stringable('multipart/related; boundary=saloon-boundary')];
    }

    public function testAsMultipartKeepsTheExistingMultipartBody(): void
    {
        $request = (new ExplicitBoundaryMultipartRequestStub)
            ->asMultipart()
            ->attach('drink', 'Moonshine')
            ->asMultipart();

        $this->assertEquals([
            new MultipartValue('nickname', 'Sam'),
            new MultipartValue('drink', 'Moonshine'),
        ], $request->body());
        $this->assertSame(
            'multipart/form-data; boundary=saloon-boundary',
            $this->pendingRequest(new TestConnector, $request)->headers()['Content-Type'],
        );
    }

    public function testWithDataAppendsFieldsToAMultipartBody(): void
    {
        $request = (new ExplicitBoundaryMultipartRequestStub)
            ->attach('file', 'Howdy', 'howdy.txt')
            ->withData([
                'nickname' => 'Alex',
                'active' => true,
                'inactive' => false,
                'nothing' => null,
                'roles' => ['admin', 'editor'],
                'profile' => ['city' => new Stringable('London')],
            ]);

        $this->assertEquals([
            new MultipartValue('nickname', 'Sam'),
            new MultipartValue('file', 'Howdy', 'howdy.txt'),
            new MultipartValue('nickname', 'Alex'),
            new MultipartValue('active', true),
            new MultipartValue('inactive', false),
            new MultipartValue('nothing', null),
            new MultipartValue('roles', ['admin', 'editor']),
            new MultipartValue('profile', ['city' => 'London']),
        ], $request->body());

        $pendingRequest = $this->pendingRequest(new TestConnector, $request);
        $body = (string) $pendingRequest->preparedBody();

        $this->assertSame('multipart/form-data; boundary=saloon-boundary', $pendingRequest->headers()['Content-Type']);
        $this->assertStringStartsWith("--saloon-boundary\r\n", $body);

        $fields = [
            ['nickname', 'Sam'],
            ['file', 'Howdy'],
            ['nickname', 'Alex'],
            ['active', '1'],
            ['inactive', ''],
            ['nothing', ''],
            ['roles[0]', 'admin'],
            ['roles[1]', 'editor'],
            ['profile[city]', 'London'],
        ];

        foreach ($fields as [$name, $value]) {
            $this->assertMatchesRegularExpression(
                '/name="' . preg_quote($name, '/') . '"[^\r\n]*\r\n(?:[^\r\n]+\r\n)*\r\n' . preg_quote($value, '/') . '\r\n/',
                $body,
            );
        }
    }

    public function testAPsrRequestSnapshotBeforeSendingKeepsAnAttachedResourceOpen(): void
    {
        $resource = fopen('php://memory', 'rw+');

        try {
            fwrite($resource, 'Howdy, Partner');
            rewind($resource);

            $request = (new UserRequest)->attach('file', $resource, 'howdy.txt');
            $snapshotBody = null;

            $request->middleware()->onRequest(function (PendingRequest $pendingRequest) use (&$snapshotBody): void {
                $snapshotBody = (string) $pendingRequest->toPsrRequest()->getBody();
            });

            $body = (string) $this->pendingRequest(new TestConnector, $request)->preparedBody();

            $this->assertStringContainsString("\r\n\r\nHowdy, Partner\r\n", $snapshotBody);
            $this->assertStringContainsString("\r\n\r\nHowdy, Partner\r\n", $body);
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
    }

    /**
     * Send the request with a mock response and return its pending request.
     */
    protected function pendingRequest(Connector $connector, Request $request): PendingRequest
    {
        return $connector->send($request, new MockClient([MockResponse::make()]))->pendingRequest();
    }
}

class ExplicitBoundaryMultipartRequestStub extends Request
{
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
     * Resolve the default body repository.
     */
    protected function defaultBodyRepository(): BodyRepository
    {
        return new MultipartBodyRepository([new MultipartValue('nickname', 'Sam')], 'saloon-boundary');
    }
}
