<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http;

use Exception;
use Generator;
use Hypervel\Http\StreamedEvent;
use Hypervel\Support\Facades\Exceptions;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\TestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EventStreamResponseTest extends TestCase
{
    public function testEventStreamResponse(): void
    {
        Route::get('/stream', function (): StreamedResponse {
            return response()->eventStream(function (): Generator {
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

    public function testEventStreamMessagesWithLineBreaksAreSentAsMultipleDataLines(): void
    {
        Route::get('/stream', function (): StreamedResponse {
            return response()->eventStream(function (): Generator {
                yield "Hello\nWorld";

                yield new StreamedEvent(
                    event: 'chunk',
                    data: "line 1\r\nline 2",
                );
            });
        });

        $content = $this->get('/stream')->streamedContent();

        $this->assertStringContainsString("event: update\ndata: Hello\ndata: World\n\n", $content);
        $this->assertStringContainsString("event: chunk\ndata: line 1\ndata: line 2\n\n", $content);
        $this->assertStringContainsString("event: update\ndata: </stream>\n\n", $content);
    }

    public function testEventStreamEndMessageWithLineBreaksIsSentAsMultipleDataLines(): void
    {
        Route::get('/stream', function (): StreamedResponse {
            return response()->eventStream(function (): Generator {
                yield 'hello';
            }, endStreamWith: new StreamedEvent(event: 'end', data: "done\nbye"));
        });

        $content = $this->get('/stream')->streamedContent();

        $this->assertStringEndsWith("event: end\ndata: done\ndata: bye\n\n", $content);
    }

    public function testEventStreamMessageCannotStartAnotherEvent(): void
    {
        Route::get('/stream', function (): StreamedResponse {
            return response()->eventStream(function (): Generator {
                yield "harmless\n\nevent: injected\ndata: evil";
            }, endStreamWith: null);
        });

        $content = $this->get('/stream')->streamedContent();

        $this->assertSame("event: update\ndata: harmless\ndata: \ndata: event: injected\ndata: data: evil\n\n", $content);
    }

    public function testEventStreamExceptionDoesNotLeakToClient(): void
    {
        Exceptions::fake();

        Route::get('/stream', function (): StreamedResponse {
            return response()->eventStream(function (): Generator {
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

        Exceptions::assertReported(fn (Exception $e): bool => $e->getMessage() === 'Something went wrong during streaming');
        Exceptions::assertReportedCount(1);
    }

    public function testEventStreamExceptionIsReportedToExceptionHandler(): void
    {
        Exceptions::fake();

        Route::get('/stream', function (): StreamedResponse {
            return response()->eventStream(function (): never {
                throw new Exception('Test exception reporting');
            });
        });

        $response = $this->get('/stream');
        $response->streamedContent();

        Exceptions::assertReported(fn (Exception $e): bool => $e->getMessage() === 'Test exception reporting');
        Exceptions::assertReportedCount(1);
    }
}
