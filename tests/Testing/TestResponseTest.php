<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing;

use Hypervel\Http\IterableStreamedResponse;
use Hypervel\Http\Request;
use Hypervel\Testing\TestResponse;
use Hypervel\Tests\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TestResponseTest extends TestCase
{
    public function testBinaryFileContentCanBeAssertedWithoutClassifyingTheResponseAsStreamed(): void
    {
        $response = TestResponse::fromBaseResponse(
            new BinaryFileResponse(__DIR__ . '/Fixtures/file.json')
        );

        $response->assertNotStreamed();
        $response->assertStreamedContent('{"foo":"bar"}');
        $response->assertStreamedJsonContent(['foo' => 'bar']);
    }

    public function testHeadStreamedContentDoesNotInvokeCallbackProducer(): void
    {
        $invocations = 0;
        $response = TestResponse::fromBaseResponse(
            new StreamedResponse(function () use (&$invocations): void {
                ++$invocations;

                echo 'content';
            }),
            Request::create('/', 'HEAD'),
        );

        $response->assertStreamedContent('');
        $this->assertSame(0, $invocations);
    }

    public function testHeadStreamedContentDoesNotInvokeIterableProducer(): void
    {
        $invocations = 0;
        $chunks = (function () use (&$invocations): iterable {
            ++$invocations;

            yield 'content';
        })();
        $response = TestResponse::fromBaseResponse(
            new IterableStreamedResponse($chunks),
            Request::create('/', 'HEAD'),
        );
        unset($chunks);

        $response->assertStreamedContent('');
        $this->assertSame(0, $invocations);
    }
}
