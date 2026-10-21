<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia\DevTools;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Http\Request;
use Hypervel\Http\UploadedFile;
use Hypervel\Inertia\DevTools\Data\IncomingEntry;
use Hypervel\Inertia\DevTools\Data\RequestType;
use Hypervel\Inertia\DevTools\DevToolsHeader;
use Hypervel\Inertia\DevTools\IncomingEntryBuilder;
use Hypervel\Inertia\DevTools\RequestAttribute;
use Hypervel\Inertia\DevTools\RequestRecorder;
use Hypervel\Inertia\DevTools\SourceLocator;
use Hypervel\Inertia\Support\Header;
use Hypervel\Tests\Inertia\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exercises IncomingEntryBuilder::build() across the full request/response matrix by
 * feeding it hand-built Request/Response pairs, and proves the recorder never lets a
 * failure during recording turn the user's real response into a 500.
 */
class IncomingEntryBuilderMatrixTest extends TestCase
{
    use InteractsWithDevToolsStorage;

    /**
     * Define the test environment.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $config = $app->make('config');

        $config->set('inertia.devtools.enabled', true);
        $config->set('inertia.devtools.except', []);
    }

    /**
     * Create an entry builder.
     */
    protected function builder(): IncomingEntryBuilder
    {
        return new IncomingEntryBuilder(new SourceLocator);
    }

    /**
     * Create a request with the given headers and collector payload.
     *
     * @param array<string, mixed> $headers
     * @param null|array<string, mixed> $payload
     */
    protected function request(string $method = 'GET', string $uri = 'http://localhost/dashboard', array $headers = [], ?array $payload = null): Request
    {
        $request = Request::create($uri, $method);

        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        if ($payload !== null) {
            $request->attributes->set(RequestAttribute::PAYLOAD, $payload);
        }

        return $request;
    }

    /**
     * Build the entry for the given request and response.
     */
    protected function build(Request $request, Response $response, bool $isPrefetch = false): IncomingEntry
    {
        return $this->builder()->build($request, $response, 'entry-id', null, $isPrefetch);
    }

    public function testRequestTypePrecedenceAcrossTheHeaderMatrix(): void
    {
        $response = new Response;

        $precognition = $this->request(headers: [Header::INERTIA => 'true', Header::PRECOGNITION => 'true', Header::PARTIAL_COMPONENT => 'Users/Form']);
        $this->assertSame(RequestType::Precognition, $this->build($precognition, $response)->requestType);

        $http = $this->request();
        $this->assertSame(RequestType::Http, $this->build($http, $response)->requestType);

        $initial = $this->request(payload: ['component' => 'Users/Index']);
        $this->assertSame(RequestType::Initial, $this->build($initial, $response)->requestType);

        $deferred = $this->request(headers: [Header::INERTIA => 'true', DevToolsHeader::DEVTOOLS_DEFERRED => '1', Header::PARTIAL_COMPONENT => 'Users/Index']);
        $this->assertSame(RequestType::Deferred, $this->build($deferred, $response)->requestType);

        $poll = $this->request(headers: [Header::INERTIA => 'true', DevToolsHeader::DEVTOOLS_POLL => '1', Header::PARTIAL_COMPONENT => 'Users/Index']);
        $this->assertSame(RequestType::Poll, $this->build($poll, $response)->requestType);

        $partial = $this->request(headers: [Header::INERTIA => 'true', Header::PARTIAL_COMPONENT => 'Users/Index']);
        $this->assertSame(RequestType::Partial, $this->build($partial, $response)->requestType);

        $prefetch = $this->request(headers: [Header::INERTIA => 'true']);
        $this->assertSame(RequestType::Prefetch, $this->build($prefetch, $response, isPrefetch: true)->requestType);

        $navigate = $this->request(headers: [Header::INERTIA => 'true']);
        $this->assertSame(RequestType::Navigate, $this->build($navigate, $response)->requestType);
    }

    public function testDeferredAndPollHeadersTakePrecedenceOverPartial(): void
    {
        $response = new Response;

        $deferredPartial = $this->request(headers: [
            Header::INERTIA => 'true',
            Header::PARTIAL_COMPONENT => 'Users/Index',
            DevToolsHeader::DEVTOOLS_DEFERRED => '1',
        ]);

        $this->assertSame(RequestType::Deferred, $this->build($deferredPartial, $response)->requestType);
    }

    public function testNonInertiaRequestWithoutAStringComponentPayloadStaysHttp(): void
    {
        $response = new Response;

        $emptyComponent = $this->request(payload: ['component' => '']);
        $this->assertSame(RequestType::Http, $this->build($emptyComponent, $response)->requestType);

        $nonStringComponent = $this->request(payload: ['component' => ['nested']]);
        $this->assertSame(RequestType::Http, $this->build($nonStringComponent, $response)->requestType);
    }

    public function testInertiaLocationHeaderWinsOverStatusForRedirectLocation(): void
    {
        $request = $this->request(headers: [Header::INERTIA => 'true']);
        $response = new Response('', 409, [Header::LOCATION => 'https://example.com/external']);

        $entry = $this->build($request, $response);

        $this->assertSame('https://example.com/external', $entry->redirectLocation);
        $this->assertSame(409, $entry->status);
    }

    public function test3xxResponsesUseTheStandardLocationHeader(): void
    {
        foreach ([301, 302, 307, 308] as $status) {
            $response = new Response('', $status, ['Location' => '/elsewhere']);

            $this->assertSame('/elsewhere', $this->build($this->request(), $response)->redirectLocation);
        }
    }

    public function testRedirectLocationIsNullWhenNoLocationApplies(): void
    {
        $this->assertNull($this->build($this->request(), new Response('', 200))->redirectLocation);
        $this->assertNull($this->build($this->request(), new Response('', 302))->redirectLocation);
        $this->assertNull($this->build($this->request(), new Response('', 404, ['Location' => '/ignored']))->redirectLocation);
        $this->assertNull($this->build($this->request(), new Response('', 500))->redirectLocation);
    }

    public function testNonTextualStreamedAndOversizedResponseBodiesAreOmitted(): void
    {
        $binary = new Response('DATA', 200, ['Content-Type' => 'application/octet-stream']);
        $this->assertSame(['status' => 'omitted', 'reason' => 'non-textual'], $this->build($this->request(), $binary)->http['responseBody']);

        $streamed = new StreamedResponse(fn (): int => print ('chunk'), 200, ['Content-Type' => 'text/plain']);
        $this->assertSame(['status' => 'omitted', 'reason' => 'streamed'], $this->build($this->request(), $streamed)->http['responseBody']);

        $huge = new Response(str_repeat('a', 256_001), 200, ['Content-Type' => 'text/plain']);
        $this->assertSame(['status' => 'omitted', 'reason' => 'too-large'], $this->build($this->request(), $huge)->http['responseBody']);
    }

    public function testResponseBodyJustUnderTheLimitIsCaptured(): void
    {
        $body = str_repeat('a', 256_000);
        $response = new Response($body, 200, ['Content-Type' => 'text/plain']);

        $this->assertSame(['status' => 'present', 'value' => $body], $this->build($this->request(), $response)->http['responseBody']);
    }

    public function testTextualAndEmptyResponseBodiesAreCaptured(): void
    {
        $empty = new Response('', 200, ['Content-Type' => 'text/plain']);
        $this->assertSame(['status' => 'empty'], $this->build($this->request(), $empty)->http['responseBody']);

        $json = new Response('{"token":"secret","name":"John"}', 200, ['Content-Type' => 'application/json']);
        config()->set('inertia.devtools.redact.keys', ['token']);
        $this->assertSame([
            'status' => 'present',
            'value' => ['token' => '[REDACTED]', 'name' => 'John'],
        ], $this->build($this->request(), $json)->http['responseBody']);

        $text = new Response('plain body', 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
        $this->assertSame(['status' => 'present', 'value' => 'plain body'], $this->build($this->request(), $text)->http['responseBody']);
    }

    public function testMalformedJsonResponseFallsBackToTheRawString(): void
    {
        $response = new Response('{not valid json', 200, ['Content-Type' => 'application/json']);

        $this->assertSame(['status' => 'present', 'value' => '{not valid json'], $this->build($this->request(), $response)->http['responseBody']);
    }

    public function testTextualResponseBodyWithInvalidUtf8IsOmittedAsBinary(): void
    {
        $response = new Response("valid\xB1\x31text", 200, ['Content-Type' => 'text/plain']);

        $this->assertSame(['status' => 'omitted', 'reason' => 'binary'], $this->build($this->request(), $response)->http['responseBody']);
    }

    public function testInertiaResponseBodyVariantsAreCapturedFromThePayload(): void
    {
        $string = $this->request(payload: ['responseBody' => 'rendered html']);
        $this->assertSame(['status' => 'present', 'value' => 'rendered html'], $this->build($string, new Response)->http['responseBody']);

        config()->set('inertia.devtools.redact.keys', ['token']);
        $array = $this->request(payload: ['responseBody' => ['props' => ['token' => 'secret', 'name' => 'John']]]);
        $this->assertSame([
            'status' => 'present',
            'value' => ['props' => ['token' => '[REDACTED]', 'name' => 'John']],
        ], $this->build($array, new Response)->http['responseBody']);

        $null = $this->request(payload: ['responseBody' => null]);
        $this->assertSame(['status' => 'empty'], $this->build($null, new Response)->http['responseBody']);

        $scalar = $this->request(payload: ['responseBody' => 42]);
        $this->assertSame(['status' => 'present', 'value' => 42], $this->build($scalar, new Response)->http['responseBody']);
    }

    public function testNonInertiaWriteRequestBodiesAreRecordedAsMetadataOnly(): void
    {
        $request = Request::create('http://localhost/save', 'POST', ['name' => 'John']);

        $this->assertSame(['status' => 'omitted', 'reason' => 'non-inertia-request'], $this->build($request, new Response)->http['requestBody']);
    }

    public function testInertiaJsonRequestBodyIsRedacted(): void
    {
        config()->set('inertia.devtools.redact.keys', ['password']);

        $request = Request::create('http://localhost/save', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_INERTIA' => 'true',
        ], json_encode(['password' => 'secret', 'name' => 'John']));

        $this->assertSame([
            'status' => 'present',
            'value' => ['password' => '[REDACTED]', 'name' => 'John'],
        ], $this->build($request, new Response)->http['requestBody']);
    }

    public function testBinaryRequestBodyIsOmitted(): void
    {
        $request = Request::create('http://localhost/upload', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/octet-stream',
            'HTTP_X_INERTIA' => 'true',
        ], "\xff\xfe\x00\x01binary");

        $this->assertSame(['status' => 'omitted', 'reason' => 'binary'], $this->build($request, new Response)->http['requestBody']);
    }

    public function testUploadedFilesAreSummarizedAndInvalidUploadsReportNullSize(): void
    {
        $valid = UploadedFile::fake()->create('resume.pdf', 12);
        $request = Request::create('http://localhost/upload', 'POST', ['name' => 'John'], [], ['avatar' => $valid], [
            'HTTP_X_INERTIA' => 'true',
        ]);

        $body = $this->build($request, new Response)->http['requestBody'];

        $this->assertSame('present', $body['status']);
        $this->assertSame('John', $body['value']['name']);
        $this->assertSame('resume.pdf', $body['value']['avatar']['name']);
        $this->assertSame(12 * 1024, $body['value']['avatar']['size']);
        $this->assertArrayHasKey('mimeType', $body['value']['avatar']);

        $invalid = new UploadedFile(__FILE__, 'ghost.pdf', 'application/pdf', UPLOAD_ERR_NO_FILE, test: true);
        $requestInvalid = Request::create('http://localhost/upload', 'POST', [], [], ['avatar' => $invalid], [
            'HTTP_X_INERTIA' => 'true',
        ]);

        $invalidBody = $this->build($requestInvalid, new Response)->http['requestBody'];

        $this->assertSame('ghost.pdf', $invalidBody['value']['avatar']['name']);
        $this->assertNull($invalidBody['value']['avatar']['size']);
    }

    public function testHeadersAreFlattenedToStringsAndSensitiveValuesRedacted(): void
    {
        config()->set('inertia.devtools.redact.headers', ['authorization']);

        $request = $this->request(headers: [
            'Authorization' => 'Bearer secret',
            'Accept' => 'application/json',
        ]);
        $request->headers->set('X-Multi', ['a', 'b']);

        $response = new Response('', 200, ['X-Response-Multi' => ['x', 'y']]);

        $entry = $this->build($request, $response);

        $this->assertSame('[REDACTED]', $entry->http['requestHeaders']['authorization']);
        $this->assertSame('application/json', $entry->http['requestHeaders']['accept']);
        $this->assertSame('a, b', $entry->http['requestHeaders']['x-multi']);
        $this->assertSame('x, y', $entry->http['responseHeaders']['x-response-multi']);
    }

    public function testPropValuesAreSanitizedAndHugeValuesArePreserved(): void
    {
        $huge = array_map(fn (int $i): array => ['id' => $i, 'name' => 'User ' . $i], range(1, 5000));

        $request = $this->request(payload: [
            'component' => 'Users/Index',
            'propValues' => [
                'users' => $huge,
                'blob' => "\xB1\x31",
                'nested' => ['ok' => 'value', 'bad' => "\xB1\x31"],
            ],
        ]);

        $entry = $this->build($request, new Response);

        $this->assertCount(5000, $entry->propValues['users']);
        $this->assertSame('[UNSERIALIZABLE]', $entry->propValues['blob']);
        $this->assertSame('value', $entry->propValues['nested']['ok']);
        $this->assertSame('[UNSERIALIZABLE]', $entry->propValues['nested']['bad']);
    }

    public function testRouteAndRenderSourceFallBackWhenAbsentFromPayload(): void
    {
        $entry = $this->build($this->request(), new Response);

        $this->assertSame(['name' => null, 'uri' => '', 'action' => null], $entry->route);
        $this->assertNull($entry->renderSource);
    }

    public function testRecorderNeverLetsABuilderFailure500TheResponse(): void
    {
        $this->app->instance(IncomingEntryBuilder::class, new ThrowingIncomingEntryBuilder(new SourceLocator));

        $request = $this->request();
        $response = new Response('real response body', 200);

        app(RequestRecorder::class)->respondedWith($request, $response);

        $this->assertSame('real response body', $response->getContent());
        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotNull($response->headers->get(DevToolsHeader::DEVTOOLS_ID));
    }

    public function testBuildDoesNotThrowOnAPathologicalResponse(): void
    {
        $request = Request::create('http://localhost/save', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_INERTIA' => 'true',
        ], "\xff\xfe not json");

        $response = new Response("body\xB1\x31", 500, ['Content-Type' => 'text/plain']);

        $entry = $this->build($request, $response);

        $this->assertSame(500, $entry->status);
    }
}

class ThrowingIncomingEntryBuilder extends IncomingEntryBuilder
{
    /**
     * Fail to build the entry.
     */
    public function build(Request $request, Response $response, string $id, ?string $batchId, bool $isPrefetch): IncomingEntry
    {
        throw new RuntimeException('builder exploded');
    }
}
