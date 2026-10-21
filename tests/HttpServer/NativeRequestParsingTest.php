<?php

declare(strict_types=1);

namespace Hypervel\Tests\HttpServer;

use Hypervel\Filesystem\Filesystem;
use Hypervel\HttpServer\RequestBridge;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use Swoole\Http\Request;

class NativeRequestParsingTest extends TestCase
{
    protected string $temporaryDirectory;

    /**
     * Prepare an isolated upload directory.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = ParallelTesting::tempDir('NativeRequestParsingTest');
        $filesystem = new Filesystem;
        $filesystem->deleteDirectory($this->temporaryDirectory);
        $filesystem->ensureDirectoryExists($this->temporaryDirectory);
    }

    /**
     * Remove the isolated upload directory.
     */
    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    public function testBearerTokenExcludesHeaderWhitespace(): void
    {
        // TODO: Remove this skip once Swoole strips trailing HTTP header whitespace upstream.
        if (version_compare(SWOOLE_VERSION, '6.3.0RC1', '<=')) {
            $this->markTestSkipped('Swoole retains trailing spaces and tabs in HTTP header values.');
        }

        $requestData = $this->requestData('GET', '/account', [
            'Authorization' => " \tBearer example-token \t",
        ], '');
        $request = Request::create();

        $this->assertSame(strlen($requestData), $request->parse($requestData));
        $this->assertTrue($request->isCompleted());
        $this->assertSame('example-token', RequestBridge::createFromSwoole($request)->bearerToken());
    }

    public function testMultipartHeadersCanBeSplitAcrossParseCalls(): void
    {
        if (version_compare(SWOOLE_VERSION, '6.3.0RC1', '<=')) {
            $this->markTestSkipped('Requires https://github.com/swoole/swoole-src/pull/6154');
        }

        $boundary = '------------------------hypervel-split';
        $body = implode("\r\n", [
            '--' . $boundary,
            'Content-Disposition: form-data; name="upload"; filename="report.txt"',
            'Content-Type: text/plain',
            '',
            'uploaded contents',
            '--' . $boundary . '--',
            '',
        ]);
        $requestData = $this->requestData('POST', '/upload', [
            'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            'Content-Length' => (string) strlen($body),
        ], $body);
        $split = strpos($requestData, 'filename="report.txt"') + strlen('filename="repo');
        $request = Request::create();

        $this->assertSame($split, $request->parse(substr($requestData, 0, $split)));
        $this->assertFalse($request->isCompleted());
        $this->assertSame(strlen($requestData) - $split, $request->parse(substr($requestData, $split)));
        $this->assertTrue($request->isCompleted());
        $this->assertSame('report.txt', $request->files['upload']['name']);
        $this->assertSame('uploaded contents', file_get_contents($request->files['upload']['tmp_name']));
    }

    public function testClientUploadMarkersAreNotTrusted(): void
    {
        if (version_compare(SWOOLE_VERSION, '6.3.0RC1', '<=')) {
            $this->markTestSkipped('Requires https://github.com/swoole/swoole-src/pull/6162');
        }

        $probe = $this->temporaryDirectory . '/probe.txt';
        file_put_contents($probe, 'probe');
        $boundary = '------------------------hypervel-marker';
        $body = implode("\r\n", [
            '--' . $boundary,
            'Content-Disposition: form-data; name="upload"; filename="report.txt"',
            'Swoole-Upload-File: ' . $probe,
            'Content-Type: text/plain',
            '',
            'uploaded contents',
            '--' . $boundary . '--',
            '',
        ]);
        $requestData = $this->requestData('POST', '/upload', [
            'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            'Content-Length' => (string) strlen($body),
        ], $body);
        $request = Request::create();

        $this->assertSame(strlen($requestData), $request->parse($requestData));
        $this->assertTrue($request->isCompleted());
        $this->assertNotSame($probe, $request->files['upload']['tmp_name']);
        $this->assertSame('uploaded contents', file_get_contents($request->files['upload']['tmp_name']));
        $this->assertFalse(is_uploaded_file($probe));
        $this->assertFileExists($probe);
    }

    public function testRequestFieldsSurviveIncrementalParseCalls(): void
    {
        if (version_compare(SWOOLE_VERSION, '6.3.0RC1', '<=')) {
            $this->markTestSkipped('Requires https://github.com/swoole/swoole-src/pull/6224');
        }

        $body = 'first=one&second=two';
        $requestData = $this->requestData('POST', '/search?term=hypervel', [
            'X-Request-Header' => 'header-value',
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Content-Length' => (string) strlen($body),
        ], $body);
        $splits = [
            strpos($requestData, 'hypervel') + 3,
            strpos($requestData, 'X-Request-Header') + 4,
            strpos($requestData, 'header-value') + 5,
            strpos($requestData, $body) + 5,
        ];

        foreach ($splits as $split) {
            $request = Request::create();

            $this->assertSame($split, $request->parse(substr($requestData, 0, $split)));
            $this->assertSame(strlen($requestData) - $split, $request->parse(substr($requestData, $split)));
            $this->assertTrue($request->isCompleted());
            $this->assertSame('/search', $request->server['request_uri']);
            $this->assertSame('hypervel', $request->get['term']);
            $this->assertSame('header-value', $request->header['x-request-header']);
            $this->assertSame(['first' => 'one', 'second' => 'two'], $request->post);
            $this->assertSame($requestData, $request->getData());
        }
    }

    /**
     * Build one literal HTTP request for the native incremental parser.
     *
     * @param array<string, string> $headers
     */
    protected function requestData(string $method, string $target, array $headers, string $body): string
    {
        $lines = [
            "{$method} {$target} HTTP/1.1",
            'Host: 127.0.0.1',
        ];

        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }

        return implode("\r\n", [...$lines, '', $body]);
    }
}
