<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http;

use Hypervel\Http\Response;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResponseBindingTest extends TestCase
{
    public function testContainerReturnsFreshResponses(): void
    {
        $first = $this->app->make(Response::class);
        $second = $this->app->make(Response::class);

        $this->assertInstanceOf(Response::class, $first);
        $this->assertInstanceOf(Response::class, $second);
        $this->assertNotSame($first, $second);
    }

    public function testContainerReturnsFreshResponsesThroughSymfonyAlias(): void
    {
        $first = $this->app->make(SymfonyResponse::class);
        $second = $this->app->make(SymfonyResponse::class);

        $this->assertInstanceOf(Response::class, $first);
        $this->assertInstanceOf(Response::class, $second);
        $this->assertNotSame($first, $second);
    }

    public function testStreamedResponseWorksInTestHarness(): void
    {
        Route::get('/test-stream', function (): StreamedResponse {
            return response()->stream(function (): void {
                echo 'streamed content';
            });
        });

        $response = $this->get('/test-stream');

        $response->assertOk();
        $response->assertStreamed();
        $response->assertStreamedContent('streamed content');
    }

    public function testGeneratorStreamedContentWorksInTestHarness(): void
    {
        Route::get('/test-stream-content', function (): StreamedResponse {
            return response()->stream(function (): iterable {
                yield 'hello ';
                yield 'world';
            });
        });

        $response = $this->get('/test-stream-content');

        $this->assertSame('hello world', $response->streamedContent());
    }

    public function testStreamDownloadWorksInTestHarness(): void
    {
        Route::get('/test-stream-download', function (): StreamedResponse {
            return response()->streamDownload(function (): void {
                echo 'download content';
            }, 'example.txt');
        });

        $response = $this->get('/test-stream-download');

        $response->assertOk();
        $response->assertDownload('example.txt');
        $response->assertStreamedContent('download content');
    }

    public function testNonStreamedResponseIsNotStreamed(): void
    {
        Route::get('/test-normal', function (): SymfonyResponse {
            return response('normal content');
        });

        $response = $this->get('/test-normal');

        $response->assertOk();
        $response->assertNotStreamed();
    }

    public function testHeadRequestDoesNotInvokeStreamedProducer(): void
    {
        $invocations = 0;

        Route::get('/test-head-stream', function () use (&$invocations): StreamedResponse {
            return response()->stream(function () use (&$invocations): void {
                ++$invocations;

                echo 'this should not be sent';
            }, 200, ['X-Custom' => 'header-value']);
        });

        $response = $this->head('/test-head-stream');

        $response->assertOk();
        $response->assertHeader('X-Custom', 'header-value');
        $response->assertStreamed();
        $this->assertSame('', $response->streamedContent());
        $this->assertSame(0, $invocations);
    }

    public function testStreamedJsonWorksWithAssertJson(): void
    {
        Route::get('/test-stream-json', function (): StreamedResponse {
            return response()->stream(function (): void {
                echo json_encode(['foo' => 'bar']);
            }, 200, ['Content-Type' => 'application/json']);
        });

        $response = $this->get('/test-stream-json');

        $response->assertStreamed();
        $response->assertJson(['foo' => 'bar']);
    }

    public function testStreamedCallbackExceptionDoesNotLeakOutputBuffering(): void
    {
        $levelBefore = ob_get_level();

        $response = $this->createTestResponse(
            new StreamedResponse(function (): void {
                throw new RuntimeException('callback error');
            })
        );

        try {
            $response->streamedContent();
        } catch (RuntimeException) {
            // Expected
        }

        $this->assertSame($levelBefore, ob_get_level());
    }
}
