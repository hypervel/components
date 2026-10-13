<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\HttpServer;

use Hypervel\Engine\Http\Client as EngineClient;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Testing\ParallelTesting;

use function Hypervel\Coroutine\parallel;

class RequestConversionTest extends HttpServerIntegrationTestCase
{
    public function testQueryStringIsRetainedInTheRequestUri(): void
    {
        $root = $this->request('GET', '/?page=1&sort=name');
        $inspect = $this->decode($this->request('GET', '/inspect?first=one&nested[value]=two'));

        $this->assertSame('/?page=1&sort=name', (string) $root->getBody());
        $this->assertSame('/inspect?first=one&nested%5Bvalue%5D=two', $inspect['uri']);
        $this->assertSame([
            'first' => 'one',
            'nested' => ['value' => 'two'],
        ], $inspect['query']);
    }

    public function testJsonAndFormBodiesAreConverted(): void
    {
        $json = $this->decode($this->request('POST', '/inspect?source=json', [
            'headers' => ['X-Integration' => 'json'],
            'json' => ['name' => 'Taylor', 'admin' => true],
        ]));
        $form = $this->decode($this->request('POST', '/inspect?source=form', [
            'headers' => ['X-Integration' => 'form'],
            'form_params' => ['name' => 'Abigail', 'roles' => ['author', 'editor']],
        ]));

        $this->assertSame('POST', $json['method']);
        $this->assertSame('json', $json['query']['source']);
        $this->assertSame(['name' => 'Taylor', 'admin' => true], $json['json']);
        $this->assertSame('json', $json['header']);

        $this->assertSame('POST', $form['method']);
        $this->assertSame('form', $form['query']['source']);
        $this->assertSame([
            'name' => 'Abigail',
            'roles' => ['author', 'editor'],
        ], $form['request']);
        $this->assertSame('form', $form['header']);
    }

    public function testMultipartFieldsAndFilesAreConverted(): void
    {
        $response = $this->request('POST', '/inspect', [
            'multipart' => [
                [
                    'name' => 'description',
                    'contents' => 'integration upload',
                ],
                [
                    'name' => 'upload',
                    'contents' => 'uploaded contents',
                    'filename' => 'nested/report.txt',
                    'headers' => ['Content-Type' => 'text/plain'],
                ],
            ],
        ]);
        $payload = $this->decode($response);

        $this->assertSame(['description' => 'integration upload'], $payload['request']);
        $this->assertSame('report.txt', $payload['file']['name']);
        $this->assertSame('report.txt', $payload['file']['path']);
        $this->assertSame('text/plain', $payload['file']['type']);
        $this->assertSame('uploaded contents', $payload['file']['contents']);
    }

    public function testQueryRequestsAreParsedByTheHttpServer(): void
    {
        if (SWOOLE_VERSION_ID <= 60203) {
            $this->markTestSkipped('Requires HTTP/1 QUERY support from https://github.com/swoole/swoole-src/pull/6256.');
        }

        $client = new EngineClient($this->getServerHost(), $this->getServerPort());
        $body = 'term=runtime&page=2';

        try {
            $response = $client->request(
                'QUERY',
                '/query?source=hypervel',
                ['Content-Type' => 'application/x-www-form-urlencoded'],
                $body,
            );
        } finally {
            $client->close();
        }
        $this->assertSame(200, $response->statusCode);
        $payload = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('QUERY', $payload['method']);
        $this->assertSame(['source' => 'hypervel'], $payload['query']);
        $this->assertSame(['term' => 'runtime', 'page' => '2'], $payload['request']);
        $this->assertSame($body, $payload['content']);
    }

    public function testLargeUploadsRejectClientProvidedInternalMarkers(): void
    {
        if (SWOOLE_VERSION_ID <= 60203) {
            $this->markTestSkipped('Requires https://github.com/swoole/swoole-src/pull/6161');
        }

        $directory = ParallelTesting::tempDir('large-upload-marker');
        $filesystem = new Filesystem;
        $filesystem->ensureDirectoryExists($directory);
        $probe = $directory . '/probe.txt';
        $filesystem->put($probe, 'probe');
        $contents = str_repeat('x', 80 * 1024);

        try {
            $valid = $this->decode($this->request('POST', '/inspect', [
                'multipart' => [[
                    'name' => 'upload',
                    'contents' => $contents,
                    'filename' => 'large.txt',
                    'headers' => ['Content-Type' => 'text/plain'],
                ]],
            ]));

            $this->assertSame($contents, $valid['file']['contents']);

            $boundary = '------------------------hypervel-upload';
            $body = implode("\r\n", [
                '--' . $boundary,
                'Content-Disposition: form-data; name="upload"; filename="large.txt"',
                'Content-Type: text/plain',
                'Swoole-Upload-File: ' . $probe,
                '',
                $contents,
                '--' . $boundary . '--',
                '',
            ]);
            $request = implode("\r\n", [
                'POST /inspect HTTP/1.1',
                'Host: ' . $this->getServerHost(),
                'Connection: close',
                'Content-Type: multipart/form-data; boundary=' . $boundary,
                'Content-Length: ' . strlen($body),
                '',
                $body,
            ]);
            $socket = stream_socket_client(sprintf(
                'tcp://%s:%d',
                $this->getServerHost(),
                $this->getServerPort(),
            ));
            $this->assertIsResource($socket);
            stream_set_timeout($socket, 2);

            try {
                $this->assertSame(strlen($request), fwrite($socket, $request));
                $response = stream_get_contents($socket);
                $this->assertIsString($response);
            } finally {
                fclose($socket);
            }

            $this->assertStringContainsString('400 Bad Request', $response);
            $this->assertFileExists($probe);
            $this->assertSame('probe', $filesystem->get($probe));
        } finally {
            $filesystem->deleteDirectory($directory);
        }
    }

    public function testConcurrentRequestsKeepTheirOwnRequestContext(): void
    {
        $host = $this->getServerHost();
        $port = $this->getServerPort();

        $responses = parallel([
            'first' => static function () use ($host, $port): string {
                $client = new EngineClient($host, $port);

                try {
                    return $client->request('GET', '/context?value=first')->getBody();
                } finally {
                    $client->close();
                }
            },
            'second' => static function () use ($host, $port): string {
                $client = new EngineClient($host, $port);

                try {
                    return $client->request('GET', '/context?value=second')->getBody();
                } finally {
                    $client->close();
                }
            },
        ]);

        $this->assertSame(['first' => 'first', 'second' => 'second'], $responses);
    }
}
