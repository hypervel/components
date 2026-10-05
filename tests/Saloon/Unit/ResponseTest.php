<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit;

use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Utils;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Http\Client\Response as HttpResponse;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Exceptions\Request\ClientException;
use Hypervel\Saloon\Exceptions\Request\RequestException;
use Hypervel\Saloon\Exceptions\Request\ServerException;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Support\Collection;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use SimpleXMLElement;
use Symfony\Component\DomCrawler\Crawler;

class ResponseTest extends TestCase
{
    /**
     * The scratch directory for saved response bodies.
     */
    protected string $tempDir;

    public function testYouCanGetTheOriginalPendingRequest(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['foo' => 'bar'], 200, ['X-Custom-Header' => 'Howdy']),
        ]);

        $response = (new TestConnector)->send(new UserRequest, $mockClient);

        $pendingRequest = $response->pendingRequest();

        $this->assertInstanceOf(PendingRequest::class, $pendingRequest);
        $this->assertInstanceOf(UserRequest::class, $pendingRequest->request());
    }

    public function testYouCanGetTheConnector(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['foo' => 'bar'], 200, ['X-Custom-Header' => 'Howdy']),
        ]);

        $request = new UserRequest;
        $connector = new TestConnector;
        $response = $connector->send($request, $mockClient);

        $this->assertSame($connector, $response->connector());
    }

    public function testYouCanGetTheOriginalRequest(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['foo' => 'bar'], 200, ['X-Custom-Header' => 'Howdy']),
        ]);

        $request = new UserRequest;
        $response = (new TestConnector)->send($request, $mockClient);

        $this->assertSame($request, $response->request());
    }

    public function testYouCanGetThePsr7Request(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['foo' => 'bar'], 200, ['X-Custom-Header' => 'Howdy']),
        ]);

        $request = new UserRequest;
        $response = (new TestConnector)->send($request, $mockClient);

        $this->assertInstanceOf(RequestInterface::class, $response->toPsrRequest());
    }

    public function testItWillThrowAnExceptionWhenYouUseTheThrowMethod(): void
    {
        $mockClient = new MockClient([
            MockResponse::make([], 500),
        ]);

        $response = (new TestConnector)->send(new UserRequest, $mockClient);

        $this->expectException(RequestException::class);

        $response->throw();
    }

    public function testItWontThrowAnExceptionIfTheRequestDidNotFail(): void
    {
        $mockClient = new MockClient([
            MockResponse::make([], 200),
        ]);

        $response = (new TestConnector)->send(new UserRequest, $mockClient);

        $this->assertSame($response, $response->throw());
    }

    public function testToExceptionWillReturnASaloonRequestException(): void
    {
        $mockClient = new MockClient([
            MockResponse::make([], 500),
        ]);

        $response = (new TestConnector)->send(new UserRequest, $mockClient);
        $exception = $response->toException();

        $this->assertInstanceOf(RequestException::class, $exception);
    }

    public function testToExceptionWontReturnAnythingIfTheRequestDidNotFail(): void
    {
        $mockClient = new MockClient([
            MockResponse::make([], 200),
        ]);

        $response = (new TestConnector)->send(new UserRequest, $mockClient);
        $exception = $response->toException();

        $this->assertNull($exception);
    }

    public function testTheOnErrorMethodWillRunACustomClosure(): void
    {
        $mockClient = new MockClient([
            MockResponse::make([], 500),
        ]);

        $response = (new TestConnector)->send(new UserRequest, $mockClient);
        $count = 0;

        $response->onError(function () use (&$count): void {
            ++$count;
        });

        $this->assertSame(1, $count);
    }

    public function testTheObjectMethodWillReturnAnObject(): void
    {
        $data = ['name' => 'Sam', 'work' => 'Codepotato'];

        $mockClient = new MockClient([
            MockResponse::make($data, 500),
        ]);

        $response = (new TestConnector)->send(new UserRequest, $mockClient);

        $dataAsObject = (object) $data;

        $this->assertEquals($dataAsObject, $response->object());
    }

    // REMOVED: object() with a dot-notation key. Saloon responses keep the HTTP client's object() method, which takes
    // decoding flags; use data_get($response->object(), 'contacts.1.name') for keyed access.

    public function testTheCollectMethodWillReturnACollection(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam', 'work' => 'Codepotato'], 500),
        ]);

        $response = (new TestConnector)->send(new UserRequest, $mockClient);
        $collection = $response->collect();

        $this->assertInstanceOf(Collection::class, $collection);
        $this->assertCount(2, $collection);
        $this->assertSame('Sam', $collection['name']);
        $this->assertSame('Codepotato', $collection['work']);

        $this->assertSame(['Sam'], $response->collect('name')->all());
        $this->assertEmpty($response->collect('age'));
    }

    // Upstream returns an empty array. Saloon responses keep the HTTP client's json() method, which returns null.
    public function testTheJsonMethodWillReturnNullIfBodyIsEmpty(): void
    {
        $mockClient = new MockClient([
            MockResponse::make('', 404),
        ]);

        $response = (new TestConnector)->send(new UserRequest, $mockClient);

        $this->assertNull($response->json());
    }

    public function testTheToPsrResponseMethodWillReturnAGuzzleResponse(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam', 'work' => 'Codepotato'], 500),
        ]);

        $response = (new TestConnector)->send(new UserRequest, $mockClient);

        $this->assertInstanceOf(PsrResponse::class, $response->toPsrResponse());
    }

    public function testYouCanGetAnIndividualHeaderFromTheResponse(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam', 'work' => 'Codepotato'], 200, ['X-Greeting' => 'Howdy']),
        ]);

        $response = (new TestConnector)->send(new UserRequest, $mockClient);

        $this->assertSame('Howdy', $response->header('X-Greeting'));
        $this->assertSame('', $response->header('X-Missing'));
    }

    public function testItWillConvertTheBodyToStringIfTheCastIsUsed(): void
    {
        $data = ['name' => 'Sam', 'work' => 'Codepotato'];

        $mockClient = new MockClient([
            MockResponse::make($data, 200, ['X-Greeting' => 'Howdy']),
        ]);

        $response = (new TestConnector)->send(new UserRequest, $mockClient);

        $this->assertSame(json_encode($data), (string) $response);
    }

    public function testItChecksStatusesCorrectly(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam', 'work' => 'Codepotato'], 200, ['X-Greeting' => 'Howdy']),
            MockResponse::make(['name' => 'Sam', 'work' => 'Codepotato'], 500, ['X-Greeting' => 'Howdy']),
            MockResponse::make(['name' => 'Sam', 'work' => 'Codepotato'], 302, ['X-Greeting' => 'Howdy']),
        ]);

        $responseA = (new TestConnector)->send(new UserRequest, $mockClient);

        $this->assertTrue($responseA->successful());
        $this->assertTrue($responseA->ok());
        $this->assertFalse($responseA->redirect());
        $this->assertFalse($responseA->failed());
        $this->assertFalse($responseA->serverError());

        $responseB = (new TestConnector)->send(new UserRequest, $mockClient);

        $this->assertFalse($responseB->successful());
        $this->assertFalse($responseB->ok());
        $this->assertFalse($responseB->redirect());
        $this->assertTrue($responseB->failed());
        $this->assertTrue($responseB->serverError());

        $responseC = (new TestConnector)->send(new UserRequest, $mockClient);

        $this->assertFalse($responseC->successful());
        $this->assertFalse($responseC->ok());
        $this->assertTrue($responseC->redirect());
        $this->assertFalse($responseC->failed());
        $this->assertFalse($responseC->serverError());
    }

    public function testTheXmlMethodWillReturnXmlAsAnArray(): void
    {
        $mockClient = new MockClient([
            new MockResponse('<SaveContactResponse xmlns="http://schemas.datacontract.org/2004/07/SmashFly.WebServices.ContactManagerService.v2"><ContactId>1168255</ContactId><Errors nil="true" xmlns:a="http://schemas.microsoft.com/2003/10/Serialization/Arrays"/><HasErrors>false</HasErrors></SaveContactResponse>', 200),
        ]);

        $response = (new TestConnector)->send(new UserRequest, $mockClient);
        $simpleXml = $response->xml();

        $this->assertInstanceOf(SimpleXMLElement::class, $simpleXml);
    }

    // REMOVED: xmlReader(). It only wraps XML Wrangler, which does not fit Hypervel; see the package README.

    // Upstream returns an ArrayStore. Saloon responses keep the HTTP client's headers() method.
    public function testTheHeadersMethodReturnsAnArray(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam', 'work' => 'Codepotato'], 200, ['X-Greeting' => 'Howdy']),
        ]);

        $response = (new TestConnector)->send(new UserRequest, $mockClient);

        $this->assertSame(['X-Greeting' => ['Howdy']], $response->headers());
    }

    // Upstream returns a single value as a string and multiple values as an array. Saloon responses keep the HTTP
    // client's methods: headers() lists every value and header() joins them.
    public function testHeadersWithMultipleValuesAreListedAndJoined(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam', 'work' => 'Codepotato'], 200, ['X-Greeting' => 'Howdy', 'X-Farewell' => ['Goodbye', 'Sam']]),
        ]);

        $response = (new TestConnector)->send(new UserRequest, $mockClient);

        $this->assertSame(['Howdy'], $response->headers()['X-Greeting']);
        $this->assertSame(['Goodbye', 'Sam'], $response->headers()['X-Farewell']);

        $this->assertSame('Howdy', $response->header('X-Greeting'));
        $this->assertSame('Goodbye, Sam', $response->header('X-Farewell'));
    }

    public function testTheDomMethodWillReturnACrawlerInstance(): void
    {
        $dom = '<p>Howdy <i>Partner</i></p>';

        $mockClient = new MockClient([
            new MockResponse($dom),
        ]);

        $response = (new TestConnector)->send(new UserRequest, $mockClient);

        $this->assertInstanceOf(Crawler::class, $response->dom());
        $this->assertEquals(new Crawler($dom), $response->dom());
    }

    public function testWhenUsingTheBodyMethodsTheStreamIsRewoundBackToTheStart(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['foo' => 'bar'], 200, ['X-Custom-Header' => 'Howdy']),
        ]);

        $response = (new TestConnector)->send(new UserRequest, $mockClient);

        $this->assertSame(['foo' => 'bar'], $response->json());
        $this->assertSame(['foo' => 'bar'], $response->array());
        $this->assertSame('{"foo":"bar"}', $response->body());
        $this->assertSame('{"foo":"bar"}', stream_get_contents($response->getRawStream()));
        $this->assertEquals((object) ['foo' => 'bar'], $response->object());
    }

    public function testItCanConvertTheResponseToADataUrl(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['foo' => 'bar'], 200, ['Content-Type' => 'application/json;encoding=utf-8']),
        ]);

        $response = (new TestConnector)->send(new UserRequest, $mockClient);

        $this->assertSame('data:application/json;encoding=utf-8;base64,eyJmb28iOiJiYXIifQ==', $response->dataUrl());
    }

    public function testIfAResponseIsChangedThroughMiddlewareTheNewInstanceIsUsed(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['foo' => 'bar'], 200, ['X-Custom-Header' => 'Howdy']),
        ]);

        // Connectors are read-only, so the middleware is registered on the request instead of the connector.
        $request = new UserRequest;

        $request->middleware()->onResponse(function (Response $response): Response {
            // Let's modify the body while sending!
            $psrResponse = $response->toPsrResponse();
            $newPsrResponse = $psrResponse->withBody(Utils::streamFor('Hello World!'));

            return $response::fromResponse(new HttpResponse($newPsrResponse), $response->pendingRequest(), $response->toPsrRequest());
        });

        $response = (new TestConnector)->send($request, $mockClient);

        $this->assertSame('Hello World!', $response->body());
        $this->assertSame(['X-Custom-Header' => ['Howdy']], $response->headers());
    }

    // The case that reads a real response as a raw resource runs against the engine test server in
    // tests/Integration/Saloon/Unit/ResponseTest.

    public function testYouCanGetTheResponseStreamAsARawResourceWithAMockResponse(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['foo' => 'bar'], 200, ['X-Custom-Header' => 'Howdy']),
        ]);

        $response = (new TestConnector)->send(new UserRequest, $mockClient);

        $resource = $response->getRawStream();

        $this->assertIsResource($resource);

        $this->assertSame('{"foo":"bar"}', stream_get_contents($resource));
    }

    #[DataProvider('saveDestinations')]
    public function testYouCanGetSaveTheResponseToAFile(bool $usePath): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['foo' => 'bar'], 200, ['X-Custom-Header' => 'Howdy']),
        ]);

        $path = $this->tempDir . '/streamToFile.json';

        $response = (new TestConnector)->send(new UserRequest, $mockClient);
        $response->saveBodyToFile($usePath ? $path : fopen($path, 'wb+'));

        $this->assertSame('{"foo":"bar"}', file_get_contents($path));
    }

    /**
     * Get whether the body is saved to a path or a resource.
     *
     * @return array<string, array{bool}>
     */
    public static function saveDestinations(): array
    {
        return [
            'path' => [true],
            'resource' => [false],
        ];
    }

    public function testTheResponseIsMacroable(): void
    {
        Response::macro('yee', fn (): string => 'haw');

        $mockClient = new MockClient([
            MockResponse::make(['foo' => 'bar'], 200, ['X-Custom-Header' => 'Howdy']),
        ]);

        $response = (new TestConnector)->send(new UserRequest, $mockClient);

        $this->assertSame('haw', $response->yee());
    }

    public function testCanDetermineIfResponseIsJson(): void
    {
        $mockClient = new MockClient([
            // JSON content type
            MockResponse::make(['foo' => 'bar'], 200, ['Content-Type' => 'application/json']),
            // JSON with charset
            MockResponse::make(['foo' => 'bar'], 200, ['Content-Type' => 'application/json; charset=utf-8']),
            // JSON with lowercase header (e.g. FastAPI, HTTP/2)
            MockResponse::make(['foo' => 'bar'], 200, ['content-type' => 'application/json']),
            // Non-JSON content type
            MockResponse::make('plain text', 200, ['Content-Type' => 'text/plain']),
            // No content type
            MockResponse::make('no content type', 200, []),
        ]);

        $connector = new TestConnector;

        $response = $connector->send(new UserRequest, $mockClient);
        $this->assertTrue($response->isJson());

        $response = $connector->send(new UserRequest, $mockClient);
        $this->assertTrue($response->isJson());

        $response = $connector->send(new UserRequest, $mockClient);
        $this->assertTrue($response->isJson());

        $response = $connector->send(new UserRequest, $mockClient);
        $this->assertFalse($response->isJson());

        $response = $connector->send(new UserRequest, $mockClient);
        $this->assertFalse($response->isJson());
    }

    public function testCanDetermineIfResponseIsXml(): void
    {
        $mockClient = new MockClient([
            // XML content type
            MockResponse::make('<?xml version="1.0"?><root></root>', 200, ['Content-Type' => 'application/xml']),
            // XML with charset
            MockResponse::make('<?xml version="1.0"?><root></root>', 200, ['Content-Type' => 'text/xml; charset=utf-8']),
            // XML with lowercase header (e.g. FastAPI, HTTP/2)
            MockResponse::make('<?xml version="1.0"?><root></root>', 200, ['content-type' => 'application/xml']),
            // Non-XML content type
            MockResponse::make('plain text', 200, ['Content-Type' => 'text/plain']),
            // No content type
            MockResponse::make('no content type', 200, []),
        ]);

        $connector = new TestConnector;

        $response = $connector->send(new UserRequest, $mockClient);
        $this->assertTrue($response->isXml());

        $response = $connector->send(new UserRequest, $mockClient);
        $this->assertTrue($response->isXml());

        $response = $connector->send(new UserRequest, $mockClient);
        $this->assertTrue($response->isXml());

        $response = $connector->send(new UserRequest, $mockClient);
        $this->assertFalse($response->isXml());

        $response = $connector->send(new UserRequest, $mockClient);
        $this->assertFalse($response->isXml());
    }

    public function testHeaderLookupIsCaseInsensitivePerHttpRfc(): void
    {
        $mockClient = new MockClient([
            MockResponse::make([], 200, ['x-my-custom-header' => 'custom-value']),
        ]);

        $response = (new TestConnector)->send(new UserRequest, $mockClient);

        $this->assertSame('custom-value', $response->header('X-My-Custom-Header'));
        $this->assertSame('custom-value', $response->header('x-my-custom-header'));
    }

    public function testIsJsonLookupCanUseCaseInsensitiveHeaders(): void
    {
        $mockClient = new MockClient([
            MockResponse::make([], 200, ['content-type' => 'application/JSON']),
        ]);

        $response = (new TestConnector)->send(new UserRequest, $mockClient);

        $this->assertTrue($response->isJson());
        $this->assertFalse($response->isXml());
    }

    public function testIsXmlLookupCanUseCaseInsensitiveHeaders(): void
    {
        $mockClient = new MockClient([
            MockResponse::make([], 200, ['content-type' => 'text/xml']),
        ]);

        $response = (new TestConnector)->send(new UserRequest, $mockClient);

        $this->assertTrue($response->isXml());
        $this->assertFalse($response->isJson());
    }

    public function testArrayAcceptsAnIntegerKey(): void
    {
        $mockClient = new MockClient([
            MockResponse::make([['name' => 'Sam'], ['name' => 'Taylor']]),
        ]);

        $response = (new TestConnector)->send(new UserRequest, $mockClient);

        $this->assertSame(['name' => 'Taylor'], $response->array(1));
        $this->assertSame('Taylor', $response->array('1.name'));
        $this->assertSame('missing', $response->array(2, 'missing'));
    }

    public function testBuiltInExceptionTypesAndTruncationArePreserved(): void
    {
        $clientResponse = $this->response(422)->truncateExceptionsAt(37);
        $serverResponse = $this->response(500)->truncateExceptionsAt(38);
        $request = new ResponseRequestStub;
        $request->throwVerdict = true;
        $generalResponse = $this->response(200, request: $request)->truncateExceptionsAt(39);

        $clientException = $clientResponse->toException();
        $serverException = $serverResponse->toException();
        $generalException = $generalResponse->toException();

        $this->assertInstanceOf(ClientException::class, $clientException);
        $this->assertSame(37, $clientException->truncateExceptionsAt);
        $this->assertInstanceOf(ServerException::class, $serverException);
        $this->assertSame(38, $serverException->truncateExceptionsAt);
        $this->assertInstanceOf(RequestException::class, $generalException);
        $this->assertSame(39, $generalException->truncateExceptionsAt);
    }

    public function testRequestFailureAndExceptionVerdictsTakePriorityOverConnectorVerdicts(): void
    {
        $connector = new ResponseConnectorStub;
        $connector->failureVerdict = true;
        $connector->customException = true;

        $request = new ResponseRequestStub;
        $request->failureVerdict = false;
        $request->throwVerdict = true;
        $request->customException = true;

        $response = $this->response(500, connector: $connector, request: $request);

        $this->assertFalse($response->failed());
        $this->assertInstanceOf(ResponseRequestExceptionStub::class, $response->toException());
    }

    public function testFailurePoliciesDriveOnErrorAndFailureNamedThrowingHelpers(): void
    {
        $connector = new ResponseConnectorStub;
        $connector->failureVerdict = true;
        $response = $this->response(200, connector: $connector);
        $called = false;

        $response->onError(function () use (&$called): void {
            $called = true;
        });

        $this->assertTrue($response->successful());
        $this->assertTrue($response->failed());
        $this->assertTrue($called);

        $request = new ResponseRequestStub;
        $request->failureVerdict = false;
        $suppressed = $this->response(404, connector: $connector, request: $request);

        $this->assertSame($suppressed, $suppressed->throwIfClientError());

        $this->expectException(ClientException::class);
        $suppressed->throwIfStatus(404);
    }

    public function testThrowPolicyCanSuppressAResponseThatStillReportsFailed(): void
    {
        $connector = new ResponseConnectorStub;
        $connector->throwVerdict = false;
        $request = new ResponseRequestStub;
        $request->throwVerdict = false;
        $response = $this->response(500, connector: $connector, request: $request);

        $this->assertTrue($response->failed());
        $this->assertNull($response->toException());
        $this->assertSame($response, $response->throw());
    }

    public function testNonSeekableBodyIsBufferedOnce(): void
    {
        $pendingRequest = $this->pendingRequest(new ResponseConnectorStub, new ResponseRequestStub);
        $psrRequest = new PsrRequest('GET', 'https://api.example.com/users');
        $httpResponse = new HttpResponse(new PsrResponse(
            body: new NoSeekStream(Utils::streamFor('response body')),
        ));
        $response = Response::fromResponse($httpResponse, $pendingRequest, $psrRequest);

        $this->assertSame('response body', $response->body());
        $this->assertSame('response body', $response->body());
        $this->assertTrue($response->stream()->isSeekable());
        $this->assertSame($psrRequest, $response->toPsrRequest());
    }

    public function testNonSeekableBodyConsumptionLeavesLinesAtTheEnd(): void
    {
        $response = $this->response(200, body: new NoSeekStream(Utils::streamFor("one\ntwo\n")));

        $this->assertSame("one\ntwo\n", $response->body());
        $this->assertSame(8, $response->stream()->tell());
        $this->assertSame([], iterator_to_array($response->lines()));

        $response->stream()->rewind();

        $this->assertSame(['one', 'two'], iterator_to_array($response->lines()));
    }

    public function testJsonLinesReadANonSeekableResponseFromItsCurrentPosition(): void
    {
        $stream = new NoSeekStream(Utils::streamFor("skip\n{\"id\":1}\n{\"id\":2}\n"));
        $stream->read(5);
        $response = $this->response(200, body: $stream);

        $this->assertSame([['id' => 1], ['id' => 2]], iterator_to_array($response->jsonLines()));
        $this->assertSame($stream, $response->stream());
    }

    public function testBodyExportsPreservePositionsAndCallerOwnedResources(): void
    {
        $response = $this->response(200, body: 'response body');
        $response->stream()->seek(4);
        $resource = fopen('php://temp', 'wb+');
        $this->assertIsResource($resource);
        fwrite($resource, 'stale contents');

        $response->saveBodyToFile($resource, false);

        $this->assertIsResource($resource);
        $this->assertSame(4, $response->stream()->tell());
        $this->assertSame('response body', stream_get_contents($resource));
        fclose($resource);

        $rawStream = $response->getRawStream();
        $this->assertIsResource($rawStream);
        $this->assertSame('response body', stream_get_contents($rawStream));
        fclose($rawStream);
    }

    public function testFailedBodyExportDoesNotCloseACallerOwnedResource(): void
    {
        $source = m::mock(StreamInterface::class);
        $source->shouldReceive('isSeekable')->once()->andReturn(false);
        $source->shouldReceive('eof')->once()->andReturn(false);
        $source->shouldReceive('read')->once()->andThrow(new RuntimeException('read failed'));
        $response = $this->response(200, body: $source);
        $resource = fopen('php://temp', 'wb+');
        $this->assertIsResource($resource);

        try {
            $response->saveBodyToFile($resource, false);
            $this->fail('The response source should fail while being copied.');
        } catch (RuntimeException $exception) {
            $this->assertSame('read failed', $exception->getMessage());
            $this->assertIsResource($resource);
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
    }

    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = ParallelTesting::tempDir('SaloonResponseTest');
        (new Filesystem)->deleteDirectory($this->tempDir);
        mkdir($this->tempDir, 0777, true);
    }

    /**
     * Clean up the test environment.
     */
    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    /**
     * Create a Saloon response for the given operation.
     */
    protected function response(
        int $status,
        StreamInterface|string $body = 'response body',
        ?Connector $connector = null,
        ?Request $request = null,
        ?PendingRequest $pendingRequest = null,
    ): Response {
        $connector ??= new ResponseConnectorStub;
        $request ??= new ResponseRequestStub;
        $pendingRequest ??= $this->pendingRequest($connector, $request);
        $psrRequest = new PsrRequest($request->method()->value, 'https://api.example.com/users');

        return Response::fromResponse(
            new HttpResponse(new PsrResponse($status, body: $body)),
            $pendingRequest,
            $psrRequest,
        );
    }

    /**
     * Create a pending request with isolated framework dependencies.
     */
    protected function pendingRequest(Connector $connector, Request $request): PendingRequest
    {
        return new PendingRequest(
            $connector,
            $request,
            m::mock(CacheFactory::class),
            m::mock(RateLimiter::class),
        );
    }
}

class ResponseConnectorStub extends Connector
{
    public ?bool $failureVerdict = null;

    public ?bool $throwVerdict = null;

    public bool $customException = false;

    /**
     * Resolve the integration base URL.
     */
    public function resolveBaseUrl(): string
    {
        return 'https://api.example.com';
    }

    /**
     * Determine if the request has failed.
     */
    public function hasRequestFailed(Response $response): ?bool
    {
        return $this->failureVerdict;
    }

    /**
     * Determine if the response should throw a request exception.
     */
    public function shouldThrowRequestException(Response $response): bool
    {
        return $this->throwVerdict ?? $response->failed();
    }

    /**
     * Get the custom request exception.
     */
    public function getRequestException(Response $response): ?RequestException
    {
        return $this->customException ? new ResponseConnectorExceptionStub($response) : null;
    }
}

class ResponseRequestStub extends Request
{
    public ?bool $failureVerdict = null;

    public ?bool $throwVerdict = null;

    public bool $customException = false;

    protected Method $method = Method::GET;

    /**
     * Resolve the request endpoint.
     */
    public function resolveEndpoint(): string
    {
        return '/users';
    }

    /**
     * Determine if the request has failed.
     */
    public function hasRequestFailed(Response $response): ?bool
    {
        return $this->failureVerdict;
    }

    /**
     * Determine if the response should throw a request exception.
     */
    public function shouldThrowRequestException(Response $response): bool
    {
        return $this->throwVerdict ?? $response->failed();
    }

    /**
     * Get the custom request exception.
     */
    public function getRequestException(Response $response): ?RequestException
    {
        return $this->customException ? new ResponseRequestExceptionStub($response) : null;
    }
}

class ResponseConnectorExceptionStub extends RequestException
{
}

class ResponseRequestExceptionStub extends RequestException
{
}
