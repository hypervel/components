<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Resources;

use Hypervel\Mcp\Exceptions\JsonRpcException;
use Hypervel\Mcp\Server\Methods\ReadResource;
use Hypervel\Mcp\Transport\JsonRpcRequest;
use Hypervel\Tests\Mcp\TestCase;

class ReadResourceTest extends TestCase
{
    public function testReturnsAValidResourceResult(): void
    {
        $resource = $this->makeResource('resource-content');
        $readResource = new ReadResource;
        $context = $this->getServerContext([
            'resources' => [
                $resource,
            ],
        ]);
        $jsonRpcRequest = new JsonRpcRequest(id: 1, method: 'resources/read', params: ['uri' => $resource->uri()]);
        $resourceResult = $readResource->handle($jsonRpcRequest, $context);

        $this->assertPartialMethodResult([
            'contents' => [
                [
                    'text' => 'resource-content',
                ],
            ],
        ], $resourceResult);
    }

    public function testReturnsAValidResourceResultForBlobResources(): void
    {
        $resource = $this->makeBinaryResource(__DIR__ . '/../../Fixtures/binary.png');
        $readResource = new ReadResource;
        $context = $this->getServerContext([
            'resources' => [
                $resource,
            ],
        ]);
        $jsonRpcRequest = new JsonRpcRequest(id: 1, method: 'resources/read', params: ['uri' => $resource->uri()]);
        $resourceResult = $readResource->handle($jsonRpcRequest, $context);

        $this->assertPartialMethodResult([
            'contents' => [
                [
                    'blob' => base64_encode(file_get_contents(__DIR__ . '/../../Fixtures/binary.png')),
                ],
            ],
        ], $resourceResult);
    }

    public function testThrowsErrorWhenUriIsMissing(): void
    {
        $this->expectException(JsonRpcException::class);
        $this->expectExceptionMessage('Missing [uri] parameter.');

        $readResource = new ReadResource;
        $context = $this->getServerContext();

        $jsonRpcRequest = new JsonRpcRequest(
            id: 1,
            method: 'resources/read',
            params: [] // intentionally omitting 'uri'
        );

        $readResource->handle($jsonRpcRequest, $context);
    }

    public function testThrowsExceptionWhenResourceIsNotFound(): void
    {
        $this->expectException(JsonRpcException::class);
        $this->expectExceptionMessage('Resource [file://resources/non-existent] not found.');

        $readResource = new ReadResource;
        $context = $this->getServerContext();

        $jsonRpcRequest = new JsonRpcRequest(
            id: 1,
            method: 'resources/read',
            params: ['uri' => 'file://resources/non-existent']
        );

        $readResource->handle($jsonRpcRequest, $context);
    }
}
