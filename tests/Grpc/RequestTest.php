<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc;

use Hypervel\Grpc\Client\Request;
use Hypervel\Tests\TestCase;

class RequestTest extends TestCase
{
    public function testExposesAnImmutableEngineRequest(): void
    {
        $request = new Request(
            path: '/example.Service/Call',
            method: 'POST',
            body: 'framed-body',
            headers: ['content-type' => 'application/grpc+proto'],
            pipeline: false,
            usePipelineRead: true,
        );

        $this->assertSame('/example.Service/Call', $request->getPath());
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('framed-body', $request->getBody());
        $this->assertSame(
            ['content-type' => 'application/grpc+proto'],
            $request->getHeaders(),
        );
        $this->assertFalse($request->isPipeline());
        $this->assertTrue($request->usesPipelineRead());
    }

    public function testSupportsThePipelineMatrixForAllCallShapes(): void
    {
        foreach ([
            'unary' => [false, true],
            'server-streaming' => [false, true],
            'client-streaming' => [true, true],
            'bidirectional-streaming' => [true, true],
        ] as $shape => [$pipeline, $pipelineRead]) {
            $request = new Request('/', 'POST', '', [], $pipeline, $pipelineRead);

            $this->assertSame($pipeline, $request->isPipeline(), $shape);
            $this->assertSame($pipelineRead, $request->usesPipelineRead(), $shape);
        }
    }
}
