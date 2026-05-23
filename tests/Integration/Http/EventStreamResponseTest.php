<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http;

use Exception;
use Hypervel\Http\StreamedEvent;
use Hypervel\Support\Facades\Exceptions;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\TestCase;

class EventStreamResponseTest extends TestCase
{
    public function testEventStreamResponse(): void
    {
        Route::get('/stream', function () {
            return response()->eventStream(function () {
                yield new StreamedEvent(
                    event: 'update',
                    data: ['message' => 'hello'],
                );

                yield new StreamedEvent(
                    event: 'update',
                    data: ['message' => 'world'],
                );
            });
        });

        $response = $this->get('/stream');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/event-stream; charset=utf-8');
        $response->assertHeader('X-Accel-Buffering', 'no');

        $content = $response->streamedContent();

        $this->assertStringContainsString("event: update\n", $content);
        $this->assertStringContainsString('data: {"message":"hello"}', $content);
        $this->assertStringContainsString('data: {"message":"world"}', $content);
        $this->assertStringContainsString('data: </stream>', $content);
    }

    public function testEventStreamExceptionDoesNotLeakToClient(): void
    {
        Exceptions::fake();

        Route::get('/stream', function () {
            return response()->eventStream(function () {
                yield new StreamedEvent(
                    event: 'update',
                    data: ['message' => 'hello'],
                );

                throw new Exception('Something went wrong during streaming');
            });
        });

        $response = $this->get('/stream');
        $content = $response->streamedContent();

        $this->assertStringContainsString("event: update\n", $content);
        $this->assertStringContainsString('data: {"message":"hello"}', $content);
        $this->assertStringNotContainsString('Something went wrong during streaming', $content);
        $this->assertStringNotContainsString("event: error\n", $content);
        $this->assertStringNotContainsString('data: </stream>', $content);

        Exceptions::assertReported(fn (Exception $e) => $e->getMessage() === 'Something went wrong during streaming');
    }

    public function testEventStreamExceptionIsReportedToExceptionHandler(): void
    {
        Exceptions::fake();

        Route::get('/stream', function () {
            return response()->eventStream(function () {
                throw new Exception('Test exception reporting');
            });
        });

        $response = $this->get('/stream');
        $response->streamedContent();

        Exceptions::assertReported(fn (Exception $e) => $e->getMessage() === 'Test exception reporting');
    }
}
