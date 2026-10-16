<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Server;

use Generator;
use Hypervel\Http\IterableStreamedResponse;
use Hypervel\Http\Request as HttpRequest;
use Hypervel\Mcp\Server\Transport\HttpTransport;
use Hypervel\Testing\TestResponse;
use Hypervel\Tests\Mcp\Fixtures\ExampleServer;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use WeakReference;

use function Hypervel\Tests\Mcp\Fixtures\callStreamingToolMessage;

class HttpTransportStreamTest extends TestCase
{
    public function testStreamsIterableResponsesReturnedFromTheStreamCallback(): void
    {
        $transport = new HttpTransport(request());

        $transport->stream(fn (): iterable => [
            '{"jsonrpc":"2.0","id":1,"result":[]}',
            '{"jsonrpc":"2.0","id":2,"result":[]}',
        ]);

        $response = $transport->run();

        $testResponse = TestResponse::fromBaseResponse($response);
        $content = $testResponse->streamedContent();

        $this->assertStringContainsString('data: {"jsonrpc":"2.0","id":1,"result":[]}', $content);
        $this->assertStringContainsString('data: {"jsonrpc":"2.0","id":2,"result":[]}', $content);
    }

    public function testStreamsGeneratorResponsesReturnedFromTheStreamCallback(): void
    {
        $transport = new HttpTransport(request());

        $transport->stream(function (): Generator {
            yield '{"jsonrpc":"2.0","id":3,"result":[]}';
            yield '{"jsonrpc":"2.0","id":4,"result":[]}';
        });

        $response = $transport->run();

        $testResponse = TestResponse::fromBaseResponse($response);
        $content = $testResponse->streamedContent();

        $this->assertStringContainsString('data: {"jsonrpc":"2.0","id":3,"result":[]}', $content);
        $this->assertStringContainsString('data: {"jsonrpc":"2.0","id":4,"result":[]}', $content);
    }

    // REMOVED: The Octane-flag duplicate; generator streaming is covered above.

    public function testDoesNotDoubleEmitWhenStreamCallbackEchoesDirectly(): void
    {
        $transport = new HttpTransport(request());

        $transport->stream(function (): void {
            echo 'data: {"jsonrpc":"2.0","id":99,"result":[]}';
            echo "\n\n";
        });

        $response = $transport->run();

        $testResponse = TestResponse::fromBaseResponse($response);
        $content = $testResponse->streamedContent();

        $this->assertSame("data: {\"jsonrpc\":\"2.0\",\"id\":99,\"result\":[]}\n\n", $content);
    }

    #[DataProvider('disconnectModes')]
    public function testSendsEachProducedMessageIncludingMessagesAfterTheLastYield(bool $cancelOnDisconnect): void
    {
        $transport = new HttpTransport(request());
        $transport->streamMessages(function () use ($transport): Generator {
            $transport->send('first');
            $transport->send('second');
            yield;
            $transport->send('last');
        }, $cancelOnDisconnect);

        $response = $transport->run();
        $this->assertSame($cancelOnDisconnect, $response instanceof IterableStreamedResponse);
        $this->assertSame('text/event-stream', $response->headers->get('Content-Type'));
        $this->assertSame('no', $response->headers->get('X-Accel-Buffering'));
        $this->assertSame("data: first\n\ndata: second\n\ndata: last\n\n", TestResponse::fromBaseResponse($response)->streamedContent());
    }

    public function testFailedWriteClosesTheModernProducerWithoutAdvancingIt(): void
    {
        $transport = new HttpTransport(request());
        $closed = false;
        $continued = false;
        $transport->streamMessages(function () use ($transport, &$closed, &$continued): Generator {
            try {
                $transport->send('first');
                yield;
                $continued = true;
                $transport->send('second');
                yield;
            } finally {
                $closed = true;
            }
        }, true);

        $response = $transport->run();
        $this->assertInstanceOf(IterableStreamedResponse::class, $response);
        $this->assertTrue($response->shouldCancelOnDisconnect());
        $written = [];
        $response->streamTo(function (string $chunk) use (&$written): bool {
            $written[] = $chunk;

            return false;
        });

        $this->assertSame(["data: first\n\n"], $written);
        $this->assertTrue($closed);
        $this->assertFalse($continued);
    }

    public function testFailedWriteStopsADirectGeneratorCallback(): void
    {
        $transport = new HttpTransport(request());
        $closed = false;
        $continued = false;
        $transport->stream(static function () use (&$closed, &$continued): Generator {
            try {
                yield 'first';
                $continued = true;
                yield 'second';
            } finally {
                $closed = true;
            }
        });

        $response = $transport->run();
        $this->assertInstanceOf(IterableStreamedResponse::class, $response);
        $this->assertFalse($response->shouldCancelOnDisconnect());
        $response->streamTo(static fn (string $chunk): bool => false);

        $this->assertTrue($closed);
        $this->assertFalse($continued);
    }

    #[DataProvider('messageHookPaths')]
    public function testPreservesTheMessageHookInIterableResponses(bool $serverStream): void
    {
        $transport = new EventHttpTransport(HttpRequest::create('/mcp', 'POST', content: json_encode(callStreamingToolMessage())));

        if ($serverStream) {
            (new ExampleServer($transport))->start();
        } else {
            $transport->stream(static function (): Generator {
                yield '{"jsonrpc":"2.0","id":1,"result":{}}';
            });
        }

        $response = $transport->run();
        $this->assertInstanceOf(IterableStreamedResponse::class, $response);
        $content = TestResponse::fromBaseResponse($response)->streamedContent();
        $events = explode("\n\n", trim($content));

        $this->assertCount($serverStream ? 3 : 1, $events);
        foreach ($events as $event) {
            $this->assertStringStartsWith("event: custom\ndata: ", $event);
        }
    }

    /**
     * Supply server-produced and directly yielded messages.
     */
    public static function messageHookPaths(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('disconnectModes')]
    public function testUnconsumedResponseDoesNotRetainItsServer(bool $cancelOnDisconnect): void
    {
        $transport = new HttpTransport(request());
        $server = new stdClass;
        $server->transport = $transport;
        $reference = WeakReference::create($server);
        $transport->onReceive(static function () use ($server, $cancelOnDisconnect): void {
            $server->transport->streamMessages(static function () use ($server): Generator {
                $server->transport->send('response');
                yield;
            }, $cancelOnDisconnect);
        });

        $response = $transport->run();
        unset($server);
        $this->assertNotNull($reference->get());
        unset($response);
        $this->assertNull($reference->get());
    }

    /**
     * Supply modern and legacy stream modes.
     */
    public static function disconnectModes(): array
    {
        return [[true], [false]];
    }
}

class EventHttpTransport extends HttpTransport
{
    /**
     * Give each message a custom SSE event name.
     */
    protected function sendStreamMessage(string $message): void
    {
        echo "event: custom\n";

        parent::sendStreamMessage($message);
    }
}
