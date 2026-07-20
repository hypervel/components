<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc;

use Google\Protobuf\Internal\Message;
use Hypervel\Container\Container;
use Hypervel\Contracts\Engine\Http\V2\ClientFactoryInterface;
use Hypervel\Engine\Http\V2\Response;
use Hypervel\Grpc\Client\ServerStreamingCall;
use Hypervel\Grpc\Client\UnaryCall;
use Hypervel\Grpc\Compression;
use Hypervel\Grpc\Health\HealthClient;
use Hypervel\Grpc\Health\V1\HealthCheckRequest;
use Hypervel\Grpc\Health\V1\HealthCheckResponse;
use Hypervel\Grpc\Health\V1\HealthCheckResponse\ServingStatus;
use Hypervel\Grpc\Health\V1\HealthListRequest;
use Hypervel\Grpc\Health\V1\HealthListResponse;
use Hypervel\Grpc\Metadata;
use Hypervel\Grpc\Protocol\FrameDecoder;
use Hypervel\Grpc\Protocol\FrameEncoder;
use Hypervel\Grpc\Protocol\MessageSerializer;
use Hypervel\Grpc\Protocol\Timeout;
use Hypervel\Tests\Grpc\Fixtures\ClientCallClient;
use Hypervel\Tests\Grpc\Fixtures\ClientCallClientFactory;
use Hypervel\Tests\TestCase;

class HealthClientTest extends TestCase
{
    private ?HealthClient $client = null;

    protected function tearDownInCoroutine(): void
    {
        $this->client?->close();
        $this->client = null;
    }

    public function testUsesCanonicalMethodsAndTypedCallShapes(): void
    {
        $engineClient = new ClientCallClient;
        $container = Container::setInstance(new Container);
        $container->instance(
            ClientFactoryInterface::class,
            new ClientCallClientFactory($engineClient),
        );
        $this->client = new HealthClient('health.example.test:50051');

        $check = $this->client->check(
            (new HealthCheckRequest)->setService('example.Greeter'),
            ['x-check' => 'one'],
            ['timeout' => 2.0],
        );
        $list = $this->client->list(
            new HealthListRequest,
            Metadata::make(['x-list' => 'two']),
        );
        $watch = $this->client->watch(
            (new HealthCheckRequest)->setService('example.Watcher'),
            ['x-watch' => 'three'],
        );

        $this->assertInstanceOf(UnaryCall::class, $check);
        $this->assertInstanceOf(UnaryCall::class, $list);
        $this->assertInstanceOf(ServerStreamingCall::class, $watch);
        $this->assertSame([
            '/grpc.health.v1.Health/Check',
            '/grpc.health.v1.Health/List',
            '/grpc.health.v1.Health/Watch',
        ], array_map(
            static fn ($request): string => $request->getPath(),
            $engineClient->sentRequests,
        ));
        $this->assertSame('one', $engineClient->sentRequests[0]->getHeaders()['x-check']);
        $this->assertSame('two', $engineClient->sentRequests[1]->getHeaders()['x-list']);
        $this->assertSame('three', $engineClient->sentRequests[2]->getHeaders()['x-watch']);
        $this->assertGreaterThan(1.9, Timeout::decode(
            $engineClient->sentRequests[0]->getHeaders()['grpc-timeout'],
        ));
        $this->assertSame(
            'example.Greeter',
            $this->decodeRequest($engineClient->sentRequests[0]->getBody(), HealthCheckRequest::class)
                ->getService(),
        );
        $this->assertInstanceOf(
            HealthListRequest::class,
            $this->decodeRequest($engineClient->sentRequests[1]->getBody(), HealthListRequest::class),
        );
        $this->assertSame(
            'example.Watcher',
            $this->decodeRequest($engineClient->sentRequests[2]->getBody(), HealthCheckRequest::class)
                ->getService(),
        );

        $this->respond(
            $engineClient,
            1,
            (new HealthCheckResponse)->setStatus(ServingStatus::SERVING),
        );
        $this->respond(
            $engineClient,
            3,
            (new HealthListResponse)->setStatuses([
                '' => (new HealthCheckResponse)->setStatus(ServingStatus::SERVING),
            ]),
        );
        $this->respond(
            $engineClient,
            5,
            (new HealthCheckResponse)->setStatus(ServingStatus::NOT_SERVING),
        );

        $this->assertInstanceOf(HealthCheckResponse::class, $check->wait());
        $this->assertInstanceOf(HealthListResponse::class, $list->wait());
        $this->assertInstanceOf(HealthCheckResponse::class, $watch->read());
        $this->assertNull($watch->read());
    }

    /**
     * Decode one framed health request.
     *
     * @param class-string<Message> $messageClass
     */
    private function decodeRequest(string $body, string $messageClass): Message
    {
        $decoder = new FrameDecoder(Compression::Identity, 1024);
        $payloads = iterator_to_array($decoder->push($body), false);
        $decoder->finish();

        $this->assertCount(1, $payloads);

        return MessageSerializer::deserialize([$messageClass, 'decode'], $payloads[0]);
    }

    /**
     * Deliver one successful health response.
     */
    private function respond(ClientCallClient $client, int $streamId, Message $message): void
    {
        $frame = (new FrameEncoder(1024))->encode($message->serializeToString());

        $client->respond(new Response(
            $streamId,
            200,
            ['content-type' => 'application/grpc+proto'],
            $frame,
            true,
        ));
        $client->respond(new Response(
            $streamId,
            200,
            ['grpc-status' => '0'],
            '',
            false,
        ));
    }
}
