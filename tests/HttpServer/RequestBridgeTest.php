<?php

declare(strict_types=1);

namespace Hypervel\Tests\HttpServer;

use Hypervel\Http\Request;
use Hypervel\Http\UploadedFile;
use Hypervel\HttpServer\RequestBridge;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Swoole\Http\Request as SwooleRequest;

class RequestBridgeTest extends TestCase
{
    public function testCreateFromSwooleWithGetRequest()
    {
        $swooleRequest = $this->createSwooleRequest(
            server: ['request_method' => 'get', 'request_uri' => '/users'],
            header: ['host' => 'example.com'],
            get: ['page' => '1', 'per_page' => '10'],
        );

        $request = RequestBridge::createFromSwoole($swooleRequest);

        $this->assertInstanceOf(Request::class, $request);
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/users', $request->getPathInfo());
        $this->assertSame('1', $request->query->get('page'));
        $this->assertSame('10', $request->query->get('per_page'));
    }

    public function testCreateFromSwooleWithPostRequest()
    {
        $swooleRequest = $this->createSwooleRequest(
            server: ['request_method' => 'post', 'request_uri' => '/users'],
            header: ['host' => 'example.com', 'content-type' => 'application/x-www-form-urlencoded'],
            post: ['name' => 'Taylor', 'email' => 'taylor@example.com'],
        );

        $request = RequestBridge::createFromSwoole($swooleRequest);

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('Taylor', $request->request->get('name'));
        $this->assertSame('taylor@example.com', $request->request->get('email'));
    }

    public function testCreateFromSwooleWithCookies()
    {
        $swooleRequest = $this->createSwooleRequest(
            server: ['request_method' => 'get', 'request_uri' => '/'],
            header: ['host' => 'example.com'],
            cookie: ['session_id' => 'abc123', 'theme' => 'dark'],
        );

        $request = RequestBridge::createFromSwoole($swooleRequest);

        $this->assertSame('abc123', $request->cookies->get('session_id'));
        $this->assertSame('dark', $request->cookies->get('theme'));
    }

    public function testCreateFromSwooleWithRawJsonBody()
    {
        $body = '{"name":"Taylor","role":"admin"}';

        $swooleRequest = $this->createSwooleRequest(
            server: ['request_method' => 'post', 'request_uri' => '/api/users'],
            header: ['host' => 'example.com', 'content-type' => 'application/json'],
            rawContent: $body,
        );

        $request = RequestBridge::createFromSwoole($swooleRequest);

        $this->assertSame($body, $request->getContent());
    }

    public function testCreateFromSwooleWithEmptyRawContent()
    {
        $swooleRequest = $this->createSwooleRequest(
            server: ['request_method' => 'get', 'request_uri' => '/'],
            header: ['host' => 'example.com'],
            rawContent: false,
        );

        $request = RequestBridge::createFromSwoole($swooleRequest);

        // false from rawContent() should become null (empty content)
        $this->assertSame('', $request->getContent());
    }

    public function testServerParamsAreUppercased()
    {
        $swooleRequest = $this->createSwooleRequest(
            server: [
                'request_method' => 'get',
                'request_uri' => '/test',
                'server_protocol' => 'HTTP/1.1',
                'remote_addr' => '192.168.1.1',
                'remote_port' => '54321',
            ],
            header: ['host' => 'example.com'],
        );

        $request = RequestBridge::createFromSwoole($swooleRequest);

        $this->assertSame('192.168.1.1', $request->server->get('REMOTE_ADDR'));
        $this->assertSame('54321', $request->server->get('REMOTE_PORT'));
    }

    public function testHeadersGetHttpPrefix()
    {
        $swooleRequest = $this->createSwooleRequest(
            server: ['request_method' => 'get', 'request_uri' => '/'],
            header: [
                'host' => 'example.com',
                'accept' => 'application/json',
                'x-custom-header' => 'custom-value',
                'authorization' => 'Bearer token123',
            ],
        );

        $request = RequestBridge::createFromSwoole($swooleRequest);

        $this->assertSame('example.com', $request->headers->get('host'));
        $this->assertSame('application/json', $request->headers->get('accept'));
        $this->assertSame('custom-value', $request->headers->get('x-custom-header'));
        $this->assertSame('Bearer token123', $request->headers->get('authorization'));
    }

    public function testContentTypeAndContentLengthGetSpecialTreatment()
    {
        $swooleRequest = $this->createSwooleRequest(
            server: ['request_method' => 'post', 'request_uri' => '/'],
            header: [
                'host' => 'example.com',
                'content-type' => 'application/json',
                'content-length' => '42',
            ],
        );

        $request = RequestBridge::createFromSwoole($swooleRequest);

        // content-type and content-length should be available as CONTENT_TYPE / CONTENT_LENGTH
        // (without HTTP_ prefix) — this is the $_SERVER convention
        $this->assertSame('application/json', $request->server->get('CONTENT_TYPE'));
        $this->assertSame('42', $request->server->get('CONTENT_LENGTH'));

        // They should also be accessible via headers (HttpFoundation normalizes from server)
        $this->assertSame('application/json', $request->headers->get('content-type'));
    }

    public function testTrailingSlashIsNormalized()
    {
        $swooleRequest = $this->createSwooleRequest(
            server: ['request_method' => 'get', 'request_uri' => '/users/'],
            header: ['host' => 'example.com'],
        );

        $request = RequestBridge::createFromSwoole($swooleRequest);

        $this->assertSame('/users', $request->server->get('REQUEST_URI'));
        $this->assertSame('/users', $request->getPathInfo());
    }

    public function testTrailingSlashNormalizationPreservesQueryString()
    {
        $swooleRequest = $this->createSwooleRequest(
            server: ['request_method' => 'get', 'request_uri' => '/users/?page=1&sort=name'],
            header: ['host' => 'example.com'],
            get: ['page' => '1', 'sort' => 'name'],
        );

        $request = RequestBridge::createFromSwoole($swooleRequest);

        $this->assertSame('/users?page=1&sort=name', $request->server->get('REQUEST_URI'));
        $this->assertSame('/users', $request->getPathInfo());
        $this->assertSame('1', $request->query->get('page'));
        $this->assertSame('name', $request->query->get('sort'));
    }

    public function testRootPathIsNotNormalized()
    {
        $swooleRequest = $this->createSwooleRequest(
            server: ['request_method' => 'get', 'request_uri' => '/'],
            header: ['host' => 'example.com'],
        );

        $request = RequestBridge::createFromSwoole($swooleRequest);

        // Root path "/" should NOT be stripped to ""
        $this->assertSame('/', $request->server->get('REQUEST_URI'));
        $this->assertSame('/', $request->getPathInfo());
    }

    public function testPathWithoutTrailingSlashIsUnchanged()
    {
        $swooleRequest = $this->createSwooleRequest(
            server: ['request_method' => 'get', 'request_uri' => '/api/users'],
            header: ['host' => 'example.com'],
        );

        $request = RequestBridge::createFromSwoole($swooleRequest);

        $this->assertSame('/api/users', $request->getPathInfo());
    }

    public function testSingleFileUpload()
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'upload_');
        file_put_contents($tmpFile, 'file contents');

        try {
            $swooleRequest = $this->createSwooleRequest(
                server: ['request_method' => 'post', 'request_uri' => '/upload'],
                header: ['host' => 'example.com', 'content-type' => 'multipart/form-data'],
                files: [
                    'avatar' => [
                        'tmp_name' => $tmpFile,
                        'name' => 'photo.jpg',
                        'type' => 'image/jpeg',
                        'error' => UPLOAD_ERR_OK,
                        'size' => 12345,
                    ],
                ],
            );

            $request = RequestBridge::createFromSwoole($swooleRequest);

            $file = $request->files->get('avatar');
            $this->assertInstanceOf(UploadedFile::class, $file);
            $this->assertSame('photo.jpg', $file->getClientOriginalName());
            $this->assertSame('image/jpeg', $file->getClientMimeType());
            $this->assertSame(UPLOAD_ERR_OK, $file->getError());
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testFileUploadUsesFullPathWhenAvailable()
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'upload_');
        file_put_contents($tmpFile, 'pdf contents');

        try {
            $swooleRequest = $this->createSwooleRequest(
                server: ['request_method' => 'post', 'request_uri' => '/upload'],
                header: ['host' => 'example.com', 'content-type' => 'multipart/form-data'],
                files: [
                    'document' => [
                        'tmp_name' => $tmpFile,
                        'name' => 'report.pdf',
                        'full_path' => 'documents/reports/report.pdf',
                        'type' => 'application/pdf',
                        'error' => UPLOAD_ERR_OK,
                        'size' => 54321,
                    ],
                ],
            );

            $request = RequestBridge::createFromSwoole($swooleRequest);

            $file = $request->files->get('document');
            $this->assertInstanceOf(UploadedFile::class, $file);
            // Symfony extracts basename for getClientOriginalName()
            $this->assertSame('report.pdf', $file->getClientOriginalName());
            // full_path is preserved in getClientOriginalPath()
            $this->assertSame('documents/reports/report.pdf', $file->getClientOriginalPath());
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testMultiFileUpload()
    {
        $tmpFile1 = tempnam(sys_get_temp_dir(), 'upload_');
        $tmpFile2 = tempnam(sys_get_temp_dir(), 'upload_');
        file_put_contents($tmpFile1, 'photo1');
        file_put_contents($tmpFile2, 'photo2');

        try {
            $swooleRequest = $this->createSwooleRequest(
                server: ['request_method' => 'post', 'request_uri' => '/upload'],
                header: ['host' => 'example.com', 'content-type' => 'multipart/form-data'],
                files: [
                    'photos' => [
                        'tmp_name' => [$tmpFile1, $tmpFile2],
                        'name' => ['photo1.jpg', 'photo2.png'],
                        'type' => ['image/jpeg', 'image/png'],
                        'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
                        'size' => [1000, 2000],
                    ],
                ],
            );

            $request = RequestBridge::createFromSwoole($swooleRequest);

            $files = $request->files->get('photos');
            $this->assertIsArray($files);
            $this->assertCount(2, $files);
            $this->assertInstanceOf(UploadedFile::class, $files[0]);
            $this->assertInstanceOf(UploadedFile::class, $files[1]);
            $this->assertSame('photo1.jpg', $files[0]->getClientOriginalName());
            $this->assertSame('photo2.png', $files[1]->getClientOriginalName());
        } finally {
            @unlink($tmpFile1);
            @unlink($tmpFile2);
        }
    }

    public function testCreateFromSwooleWithNullFields()
    {
        // Swoole may have null for optional fields
        $swooleRequest = $this->createSwooleRequest(
            server: ['request_method' => 'get', 'request_uri' => '/'],
            header: ['host' => 'example.com'],
            get: null,
            post: null,
            cookie: null,
            files: null,
        );

        $request = RequestBridge::createFromSwoole($swooleRequest);

        $this->assertInstanceOf(Request::class, $request);
        $this->assertSame('GET', $request->getMethod());
        $this->assertEmpty($request->query->all());
        $this->assertEmpty($request->request->all());
        $this->assertEmpty($request->cookies->all());
        $this->assertEmpty($request->files->all());
    }

    public function testSchemeAndHost()
    {
        $swooleRequest = $this->createSwooleRequest(
            server: [
                'request_method' => 'get',
                'request_uri' => '/test',
                'server_port' => '443',
            ],
            header: [
                'host' => 'example.com',
                'x-forwarded-proto' => 'https',
            ],
        );

        $request = RequestBridge::createFromSwoole($swooleRequest);

        $this->assertSame('example.com', $request->getHost());
    }

    /**
     * Create a mock Swoole request with the given parameters.
     */
    private function createSwooleRequest(
        array $server = [],
        array $header = [],
        ?array $get = null,
        ?array $post = null,
        ?array $cookie = null,
        ?array $files = null,
        string|false $rawContent = false,
    ): SwooleRequest {
        $swooleRequest = m::mock(SwooleRequest::class);
        $swooleRequest->server = $server;
        $swooleRequest->header = $header;
        $swooleRequest->get = $get;
        $swooleRequest->post = $post;
        $swooleRequest->cookie = $cookie;
        $swooleRequest->files = $files;
        $swooleRequest->shouldReceive('rawContent')->andReturn($rawContent);

        return $swooleRequest;
    }
}
