<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai;

use GuzzleHttp\Exception\TransferException;
use Hypervel\Ai\Streaming\Events\StreamEnd;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine;
use Hypervel\Tests\Ai\Fixtures\Agents\OpenAiAgent;
use Hypervel\Tests\Ai\Fixtures\OpenAiHelpers;
use Hypervel\Tests\Http\Fixtures\LoopbackHttpServer;
use PHPUnit\Framework\Attributes\DataProvider;
use Swoole\Coroutine\CanceledException;
use Swoole\Coroutine\Socket;
use Throwable;

class HttpStreamingTest extends TestCase
{
    use OpenAiHelpers;

    public function testProviderTextArrivesBeforeCompletionAndCanBeReplayed(): void
    {
        $release = new Channel(1);
        $first = $this->ssePayload([$this->responseCreated(), $this->outputTextDelta('Hello')]);
        $last = $this->ssePayload([$this->outputTextDelta(' world'), $this->outputTextDone('Hello world'), $this->responseCompleted(2, 2)]);
        $server = LoopbackHttpServer::start([
            static function (Socket $client) use ($first, $last, $release): void {
                $client->sendAll("HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nContent-Length: " . strlen($first . $last) . "\r\nConnection: close\r\n\r\n" . $first);

                // Waiting for completion before yielding text would time out here and truncate the response.
                if ($release->pop(2) === true) {
                    $client->sendAll($last);
                }
            },
        ]);
        $this->useProviderServer($server);

        $response = (new OpenAiAgent)->stream('Hello');
        $events = [];

        try {
            foreach ($response as $event) {
                $events[] = $event;

                if ($event instanceof TextDelta && $event->delta === 'Hello') {
                    $release->push(true);
                }
            }
        } finally {
            $release->close();
        }

        $this->assertInstanceOf(StreamEnd::class, $events[array_key_last($events)]);
        $this->assertSame('Hello world', $response->text);
        $this->assertSame($events, iterator_to_array($response));
        $this->assertStringContainsString('POST /responses HTTP/1.1', $server->request());
    }

    public function testAbandoningConsumptionClosesTheProviderConnection(): void
    {
        $closed = new Channel(1);
        $first = $this->ssePayload([$this->responseCreated(), $this->outputTextDelta('Hello')]);
        $server = LoopbackHttpServer::start([
            static function (Socket $client) use ($first, $closed): void {
                $client->sendAll("HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nConnection: close\r\n\r\n" . $first);
                $closed->push($client->recv(2));
            },
            [
                'headers' => ['Content-Type' => 'text/event-stream'],
                'body' => $this->ssePayload([$this->responseCreated(), $this->outputTextDelta('Again'), $this->outputTextDone('Again'), $this->responseCompleted(2, 2)]),
            ],
        ]);
        $this->useProviderServer($server);

        $response = (new OpenAiAgent)->stream('Hello');

        try {
            foreach ($response as $event) {
                if ($event instanceof TextDelta) {
                    break;
                }
            }

            $this->assertInstanceOf(TextDelta::class, $event);
            $this->assertSame('', $closed->pop(3), 'Abandoning the iterator must close its unfinished provider response.');
            $next = (new OpenAiAgent)->stream('Another request');
            iterator_to_array($next);
            $this->assertSame('Again', $next->text);
        } finally {
            $closed->close();
        }
    }

    public function testChunkedProviderEventsPreserveSplitJsonUtf8AndLineEndings(): void
    {
        $delta = 'data: ' . json_encode($this->outputTextDelta('Café'), JSON_UNESCAPED_UNICODE) . "\r\n\r\n";
        $utf8Offset = strpos($delta, 'é') + 1;
        $chunks = [
            $this->ssePayload([$this->responseCreated()]),
            substr($delta, 0, 12),
            substr($delta, 12, $utf8Offset - 12),
            substr($delta, $utf8Offset, -3),
            substr($delta, -3),
            $this->ssePayload([$this->outputTextDone('Café'), $this->responseCompleted(2, 2)]),
        ];
        $server = LoopbackHttpServer::start([
            static function (Socket $client) use ($chunks): void {
                $client->sendAll("HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n");

                foreach ($chunks as $chunk) {
                    $client->sendAll(dechex(strlen($chunk)) . "\r\n" . $chunk . "\r\n");
                    usleep(1000);
                }

                $client->sendAll("0\r\n\r\n");
            },
        ]);
        $this->useProviderServer($server);

        $response = (new OpenAiAgent)->stream('Hello');
        $events = iterator_to_array($response);

        $this->assertInstanceOf(StreamEnd::class, $events[array_key_last($events)]);
        $this->assertSame('Café', $response->text);
    }

    public function testTruncatedProviderBodyDoesNotCompleteTheAgentResponse(): void
    {
        $release = new Channel(1);
        $first = $this->ssePayload([$this->responseCreated(), $this->outputTextDelta('Hello')]);
        $server = LoopbackHttpServer::start([
            static function (Socket $client) use ($first, $release): void {
                $client->sendAll("HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nContent-Length: " . (strlen($first) + 100) . "\r\nConnection: close\r\n\r\n" . $first);
                $release->pop(2);
            },
        ]);
        $this->useProviderServer($server);
        $completed = false;
        $response = (new OpenAiAgent)->stream('Hello')->then(function () use (&$completed): void {
            $completed = true;
        });
        $thrown = null;
        $events = [];

        try {
            foreach ($response as $event) {
                $events[] = $event;

                if ($event instanceof TextDelta) {
                    $release->push(true);
                }
            }
        } catch (TransferException $exception) {
            $thrown = $exception;
        } finally {
            $release->close();
        }

        $this->assertInstanceOf(TransferException::class, $thrown);
        $this->assertFalse($completed);
        $this->assertInstanceOf(TextDelta::class, $events[array_key_last($events)]);
    }

    #[DataProvider('cancellationPhases')]
    public function testCancellationClosesTheProviderConnection(bool $sendBody): void
    {
        $closed = new Channel(1);
        $received = new Channel(1);
        $finished = new Channel(1);
        $first = $this->ssePayload([$this->responseCreated(), $this->outputTextDelta('Hello')]);
        $server = LoopbackHttpServer::start([
            static function (Socket $client) use ($sendBody, $first, $closed): void {
                if ($sendBody) {
                    $client->sendAll("HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nConnection: close\r\n\r\n" . $first);
                }

                $closed->push($client->recv(2));
            },
            [
                'headers' => ['Content-Type' => 'text/event-stream'],
                'body' => $this->ssePayload([$this->responseCreated(), $this->outputTextDelta('Again'), $this->outputTextDone('Again'), $this->responseCompleted(2, 2)]),
            ],
        ]);
        $this->useProviderServer($server);
        $reader = Coroutine::create(static function () use ($received, $finished): void {
            try {
                foreach ((new OpenAiAgent)->stream('Hello') as $event) {
                    if ($event instanceof TextDelta) {
                        $received->push($event->delta);
                    }
                }

                $finished->push(null);
            } catch (Throwable $exception) {
                $finished->push($exception);
            }
        });

        try {
            $this->assertStringContainsString('POST /responses HTTP/1.1', $server->request());

            if ($sendBody) {
                $this->assertSame('Hello', $received->pop(2));
            }

            // Channel delivery can resume us before the reader enters its I/O wait.
            usleep(1000);
            $this->assertTrue(Coroutine::cancelById($reader->getId()));
            $this->assertInstanceOf(CanceledException::class, $finished->pop(2));
            $this->assertSame('', $closed->pop(3));
            $next = (new OpenAiAgent)->stream('Another request');
            iterator_to_array($next);
            $this->assertSame('Again', $next->text);
        } finally {
            if (Coroutine::exists($reader->getId())) {
                Coroutine::cancelById($reader->getId());
                Coroutine::join([$reader->getId()], 2);
            }

            $closed->close();
            $received->close();
            $finished->close();
        }
    }

    /**
     * Supply the provider phases that may wait without receiving data.
     */
    public static function cancellationPhases(): array
    {
        return [
            'before headers' => [false],
            'during a quiet body' => [true],
        ];
    }

    /**
     * Point the OpenAI provider at the loopback server.
     */
    private function useProviderServer(LoopbackHttpServer $server): void
    {
        config(['ai.providers.openai.key' => 'test-key', 'ai.providers.openai.url' => 'http://127.0.0.1:' . $server->port]);
    }
}
