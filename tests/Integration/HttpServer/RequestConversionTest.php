<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\HttpServer;

use Hypervel\Engine\Http\Client as EngineClient;

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
