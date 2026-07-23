<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc;

use Hypervel\Container\Container;
use Hypervel\Contracts\Engine\Http\V2\ClientFactoryInterface;
use Hypervel\Engine\Http\V2\Response;
use Hypervel\Grpc\Compression;
use Hypervel\Grpc\Protocol\FrameDecoder;
use Hypervel\Grpc\Protocol\FrameEncoder;
use Hypervel\Tests\Grpc\Fixtures\ClientCallClient;
use Hypervel\Tests\Grpc\Fixtures\ClientCallClientFactory;
use Hypervel\Tests\Grpc\Fixtures\TestReply;
use Hypervel\Tests\Grpc\Fixtures\TestRequest;
use Hypervel\Tests\Grpc\Fixtures\TestServiceClient;
use Hypervel\Tests\TestCase;

use function Hypervel\Coroutine\parallel;

class ClientCoroutineIsolationTest extends TestCase
{
    public function testClientsCallsMetadataDeadlinesAndResponsesRemainIsolatedAcrossCoroutines(): void
    {
        // Protobuf's generated descriptor registration is not coroutine-safe on first use.
        new TestRequest;

        $clients = [];
        $engines = [];
        $tasks = [];

        for ($clientIndex = 0; $clientIndex < 4; ++$clientIndex) {
            $clientEngines = [new ClientCallClient, new ClientCallClient];
            $container = Container::setInstance(new Container);
            $container->instance(
                ClientFactoryInterface::class,
                new ClientCallClientFactory(...$clientEngines),
            );
            $client = new TestServiceClient('example.test:50051', [
                'connections' => 2,
                'metadata' => ['client-id' => "client-{$clientIndex}"],
            ]);
            $clients[] = $client;

            foreach ($clientEngines as $engineIndex => $engine) {
                $callId = "client-{$clientIndex}-call-{$engineIndex}";
                $engines[] = [$engine, "client-{$clientIndex}"];
                $tasks[$callId] = static function () use ($client, $callId, $engineIndex): array {
                    $call = $client->unary(
                        (new TestRequest)->setValue($callId),
                        ['call-id' => $callId],
                        ['timeout' => 1.0 + ($engineIndex / 10)],
                    );
                    usleep(100);
                    $reply = $call->wait();

                    return [
                        $reply->getValue(),
                        $call->metadata()->first('x-call-id'),
                        $call->trailers()->first('x-call-id'),
                    ];
                };
            }
        }

        foreach ($engines as $engineIndex => [$engine, $clientId]) {
            $tasks["responder-{$engineIndex}"] = function () use ($engine, $clientId): null {
                while ($engine->sentRequests === []) {
                    usleep(100);
                }

                $request = $engine->sentRequests[0];
                $this->assertSame($clientId, $request->getHeaders()['client-id']);
                $this->assertArrayHasKey('grpc-timeout', $request->getHeaders());
                $callId = $request->getHeaders()['call-id'];
                $decoder = new FrameDecoder(Compression::Identity, 1024);
                $payloads = iterator_to_array($decoder->push($request->getBody()), false);
                $decoder->finish();
                $this->assertCount(1, $payloads);
                $message = new TestRequest;
                $message->mergeFromString($payloads[0]);
                $this->assertSame($callId, $message->getValue());
                usleep(100);

                $encoder = new FrameEncoder(1024);
                $reply = (new TestReply)->setValue("reply:{$callId}");
                $engine->respond(new Response(
                    1,
                    200,
                    [
                        'content-type' => 'application/grpc+proto',
                        'x-call-id' => $callId,
                    ],
                    $encoder->encode($reply->serializeToString()),
                    true,
                ));
                $engine->respond(new Response(
                    1,
                    200,
                    [
                        'grpc-status' => '0',
                        'x-call-id' => $callId,
                    ],
                    '',
                    false,
                ));

                return null;
            };
        }

        try {
            $results = parallel($tasks);

            for ($clientIndex = 0; $clientIndex < 4; ++$clientIndex) {
                for ($callIndex = 0; $callIndex < 2; ++$callIndex) {
                    $callId = "client-{$clientIndex}-call-{$callIndex}";
                    $this->assertSame([
                        "reply:{$callId}",
                        $callId,
                        $callId,
                    ], $results[$callId]);
                }
            }
        } finally {
            foreach ($clients as $client) {
                $client->close();
            }
        }
    }
}
