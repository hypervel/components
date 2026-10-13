<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Http;

use Hypervel\Context\RequestContext;
use Hypervel\Http\Request;
use Hypervel\Http\UploadedFile;
use Hypervel\Routing\Router;
use Hypervel\Sentry\Http\HypervelRequestFetcher;
use Hypervel\Tests\Sentry\TestCase;
use PHPUnit\Framework\Attributes\TestWith;
use Psr\Http\Message\ServerRequestInterface;

class HypervelRequestFetcherTest extends TestCase
{
    /**
     * Define the test routes.
     */
    protected function defineRoutes(Router $router): void
    {
        $router->get('/', function (): string {
            return 'Hello!';
        });
    }

    public function testPsr7InstanceCanBeResolved(): void
    {
        // Make a request so RequestContext is populated
        $this->get('/');

        $fetcher = new HypervelRequestFetcher;
        $request = $fetcher->fetchRequest();

        $this->assertInstanceOf(ServerRequestInterface::class, $request);
    }

    public function testEmptyParsedBodyIsDroppedForRequestsThatAreNotForms(): void
    {
        $request = Request::create('http://localhost/', 'POST', server: ['CONTENT_TYPE' => 'text/plain', 'CONTENT_LENGTH' => '5'], content: 'hello');

        $this->assertNull($this->fetchRequest($request)->getParsedBody());
    }

    public function testEmptyParsedBodyIsKeptForForms(): void
    {
        $request = Request::create('http://localhost/', 'POST', server: ['CONTENT_TYPE' => 'Multipart/Form-Data; boundary=foo']);

        $this->assertSame([], $this->fetchRequest($request)->getParsedBody());
    }

    public function testParsedBodyIsKept(): void
    {
        $request = Request::create('http://localhost/', 'POST', server: ['CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => '13'], content: '{"foo":"bar"}');

        $this->assertSame(['foo' => 'bar'], $this->fetchRequest($request)->getParsedBody());
    }

    public function testParsedBodyIsDroppedForRequestsThatAreNotFormsWithDataCollection(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
        ]);

        // The PSR-7 bridge uses the decoded payload as the parsed body of JSON requests
        $request = Request::create('http://localhost/', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"token":"secret"}');

        $this->assertNull($this->fetchRequest($request)->getParsedBody());
    }

    public function testParsedBodyIsKeptForFormsWithDataCollection(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
        ]);

        $request = Request::create('http://localhost/', 'POST', ['foo' => 'bar'], server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded']);

        $this->assertSame(['foo' => 'bar'], $this->fetchRequest($request)->getParsedBody());
    }

    public function testRawQueryStringIsUsedWithDataCollection(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
        ]);

        $request = Request::create('http://localhost/?q=a+b&page=5');

        $this->assertSame('q=a+b&page=5', $this->fetchRequest($request)->getUri()->getQuery());
    }

    public function testQueryStringIsNotChangedWithoutDataCollection(): void
    {
        $request = Request::create('http://localhost/?q=a+b&page=5');

        $this->assertSame('q=a+b&page=5', $this->fetchRequest($request)->getUri()->getQuery());
    }

    public function testCookiesWithNumericNamesAreFetched(): void
    {
        $request = Request::create('http://localhost/', cookies: ['123' => 'value']);

        $this->assertSame([123 => 'value'], $this->fetchRequest($request)->getCookieParams());
    }

    public function testParsedBodyIsKeptForMultipartFormsWithDataCollection(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
        ]);

        $request = Request::create('http://localhost/', 'POST', ['foo' => 'bar'], server: ['CONTENT_TYPE' => 'Multipart/Form-Data; boundary=foo']);

        $this->assertSame(['foo' => 'bar'], $this->fetchRequest($request)->getParsedBody());
    }

    public function testBodyOverTheLegacyLimitIsNotDecoded(): void
    {
        // The default `medium` request body size limits the legacy body collector to 10,000 bytes
        $content = json_encode(['foo' => str_repeat('a', 10_000)]);
        $request = Request::create('http://localhost/', 'POST', server: ['CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => (string) strlen($content)], content: $content);

        $fetchedRequest = $this->fetchRequest($request);

        $this->assertNull($fetchedRequest->getParsedBody());
        $this->assertSame('', (string) $fetchedRequest->getBody());
    }

    #[TestWith([null, 'never', 13, true, false])]
    #[TestWith([null, 'medium', 13, false, false])]
    #[TestWith([null, 'always', 100_001, true, true])]
    #[TestWith([[], 'small', 1000, true, true])]
    #[TestWith([[], 'small', 1001, true, false])]
    #[TestWith([[], 'medium', 13, false, true])]
    #[TestWith([['http_bodies' => []], 'medium', 13, true, false])]
    #[TestWith([[], 'never', 13, true, false])]
    public function testBodyIsReadOnceOnlyWhenCollectionPermitsIt(
        ?array $collection,
        string $limit,
        int $size,
        bool $knownLength,
        bool $eligible,
    ): void {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => $collection,
            'sentry.max_request_body_size' => $limit,
        ]);
        $content = str_pad('{"foo":"bar"}', $size);
        $stream = fopen('php://memory', 'w+');

        try {
            fwrite($stream, $content);
            $request = SentryBodyReadingRequest::create('http://localhost/', 'POST', server: [
                'CONTENT_TYPE' => 'application/json',
            ], content: $stream);

            if ($knownLength) {
                $request->headers->set('Content-Length', (string) strlen($content));
            }

            $fetchedRequest = $this->fetchRequest($request);

            $this->assertSame($eligible ? 1 : 0, $request->contentReads);
            $this->assertSame($eligible ? $content : '', (string) $fetchedRequest->getBody());
            $this->assertSame($eligible && $collection === null ? ['foo' => 'bar'] : null, $fetchedRequest->getParsedBody());
        } finally {
            fclose($stream);
        }
    }

    public function testUploadedFilesAreConvertedForTheLegacyBodyCollector(): void
    {
        $request = Request::create('http://localhost/', 'POST', files: [
            'avatar' => UploadedFile::fake()->create('avatar.png', 1),
            'documents' => [UploadedFile::fake()->create('first.pdf', 2)],
            'missing' => ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0],
        ], server: ['CONTENT_TYPE' => 'multipart/form-data; boundary=foo', 'CONTENT_LENGTH' => '100']);

        $uploadedFiles = $this->fetchRequest($request)->getUploadedFiles();

        $this->assertSame('avatar.png', $uploadedFiles['avatar']->getClientFilename());
        $this->assertSame('image/png', $uploadedFiles['avatar']->getClientMediaType());
        $this->assertSame(1024, $uploadedFiles['avatar']->getSize());
        $this->assertSame('first.pdf', $uploadedFiles['documents'][0]->getClientFilename());
        $this->assertSame(2048, $uploadedFiles['documents'][0]->getSize());
        $this->assertSame(UPLOAD_ERR_NO_FILE, $uploadedFiles['missing']->getError());
    }

    public function testMovedUploadedFilesAreConverted(): void
    {
        $handle = tmpfile();
        $path = stream_get_meta_data($handle)['uri'];
        $request = Request::create('http://localhost/', 'POST', files: [
            'avatar' => new UploadedFile($path, 'avatar.png', 'image/png', UPLOAD_ERR_OK, true),
        ], server: ['CONTENT_TYPE' => 'multipart/form-data; boundary=foo', 'CONTENT_LENGTH' => '100']);

        // The application moved the upload, so it no longer exists at its temporary path
        unlink($path);

        $uploadedFile = $this->fetchRequest($request)->getUploadedFiles()['avatar'];

        $this->assertSame('avatar.png', $uploadedFile->getClientFilename());
        $this->assertSame(0, $uploadedFile->getSize());
    }

    public function testHeadersRejectedByPsr7AreSkipped(): void
    {
        $request = Request::create('http://localhost/', server: ['HTTP_X_VALID' => 'value']);
        $request->headers->set('X-Null', null);
        $request->headers->set('123', 'numeric');

        $fetchedRequest = $this->fetchRequest($request);

        $this->assertSame('value', $fetchedRequest->getHeaderLine('X-Valid'));
        $this->assertFalse($fetchedRequest->hasHeader('X-Null'));
        $this->assertSame('numeric', $fetchedRequest->getHeaderLine('123'));
    }

    public function testHeaderValuesAndNumericNamesArePreserved(): void
    {
        $request = Request::create('http://localhost/');
        $request->headers->set('X-Multiple', [' first ', 'second']);
        $request->headers->set('123', 'numeric');

        $fetchedRequest = $this->fetchRequest($request);

        $this->assertSame(['first', 'second'], $fetchedRequest->getHeader('X-Multiple'));
        $this->assertSame('numeric', $fetchedRequest->getHeaderLine('123'));
    }

    /**
     * Fetch the given request as the current request.
     */
    private function fetchRequest(Request $request): ServerRequestInterface
    {
        RequestContext::set($request);

        $fetchedRequest = (new HypervelRequestFetcher)->fetchRequest();

        $this->assertNotNull($fetchedRequest);

        return $fetchedRequest;
    }
}

class SentryBodyReadingRequest extends Request
{
    public int $contentReads = 0;

    /**
     * Count reads of the request's resource-backed content.
     *
     * @return resource|string
     */
    public function getContent(bool $asResource = false): mixed
    {
        ++$this->contentReads;

        return parent::getContent($asResource);
    }
}
