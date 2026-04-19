<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http;

use Hypervel\Context\ResponseContext;
use Hypervel\Http\Response;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\TestCase;
use RuntimeException;

class ResponseBindingTest extends TestCase
{
    public function testContainerResolvesResponseFromContext()
    {
        $contextResponse = new Response;
        ResponseContext::set($contextResponse);

        try {
            $resolved = $this->app->make(Response::class);

            $this->assertSame($contextResponse, $resolved);
        } finally {
            ResponseContext::forget();
        }
    }

    public function testContainerResolvesSymfonyResponseFromContext()
    {
        $contextResponse = new Response;
        ResponseContext::set($contextResponse);

        try {
            $resolved = $this->app->make(\Symfony\Component\HttpFoundation\Response::class);

            $this->assertSame($contextResponse, $resolved);
        } finally {
            ResponseContext::forget();
        }
    }

    public function testContainerReturnsFreshResponseWhenNoContext()
    {
        ResponseContext::forget();

        $first = $this->app->make(Response::class);
        $second = $this->app->make(Response::class);

        $this->assertInstanceOf(Response::class, $first);
        $this->assertInstanceOf(Response::class, $second);
        $this->assertNotSame($first, $second);
    }

    public function testStreamedResponseWorksInTestHarness()
    {
        Route::get('/test-stream', function () {
            $response = app(Response::class);

            return $response->stream(function ($output) {
                $output->write('streamed content');
            });
        });

        $response = $this->get('/test-stream');

        $response->assertOk();
        $response->assertStreamed();
        $response->assertStreamedContent('streamed content');
    }

    public function testStreamedContentReadFromFakeWritable()
    {
        Route::get('/test-stream-content', function () {
            $response = app(Response::class);

            return $response->stream(function ($output) {
                $output->write('hello ');
                $output->write('world');
            });
        });

        $response = $this->get('/test-stream-content');

        $this->assertSame('hello world', $response->streamedContent());
    }

    public function testNonStreamedResponseIsNotStreamed()
    {
        Route::get('/test-normal', function () {
            return response('normal content');
        });

        $response = $this->get('/test-normal');

        $response->assertOk();
        $response->assertNotStreamed();
    }

    public function testHeadRequestSendsHeadersButNoBody()
    {
        Route::get('/test-head-stream', function () {
            $response = app(Response::class);

            return $response->stream(function ($output) {
                $output->write('this should not be sent');
            }, ['X-Custom' => 'header-value']);
        });

        $response = $this->head('/test-head-stream');

        $response->assertOk();
        $response->assertHeader('X-Custom', 'header-value');
        $response->assertStreamed();
        $this->assertSame('', $response->streamedContent());
    }

    public function testStreamedJsonWorksWithAssertJson()
    {
        Route::get('/test-stream-json', function () {
            $response = app(Response::class);

            return $response->stream(function ($output) {
                $output->write(json_encode(['foo' => 'bar']));
            }, ['Content-Type' => 'application/json']);
        });

        $response = $this->get('/test-stream-json');

        $response->assertStreamed();
        $response->assertJson(['foo' => 'bar']);
    }

    public function testStreamedCallbackExceptionDoesNotLeakOutputBuffering()
    {
        $levelBefore = ob_get_level();

        $response = $this->createTestResponse(
            new \Symfony\Component\HttpFoundation\StreamedResponse(function () {
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
