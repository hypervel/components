<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\BedrockRuntime\Exception\BedrockRuntimeException;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Closure;
use Exception;
use GuzzleHttp\Psr7\Utils;
use Hypervel\Ai\Gateway\Bedrock\BedrockRerankingGateway;
use Hypervel\Ai\Gateway\Bedrock\BedrockTextGateway;
use Hypervel\Ai\Gateway\TextGenerationLoop;
use Hypervel\Ai\Gateway\TextGenerationOptions;
use Hypervel\Ai\Providers\BedrockProvider;
use Hypervel\Ai\Providers\Provider;

trait BedrockHelpers
{
    /**
     * Create a client returning a Converse result.
     */
    protected function fakeBedrockConverse(array $result): BedrockRuntimeClient
    {
        return $this->bedrockClient(new MockHandler([new Result($result)]));
    }

    /**
     * Create a client returning an invocation result.
     */
    protected function fakeBedrockInvoke(array $body): BedrockRuntimeClient
    {
        return $this->bedrockClient($this->bedrockInvokeMock($body));
    }

    /**
     * Create an invocation response handler.
     */
    protected function bedrockInvokeMock(array|string $body): MockHandler
    {
        return new MockHandler([new Result([
            'body' => Utils::streamFor(is_string($body) ? $body : json_encode($body)),
        ])]);
    }

    /**
     * Create a client returning an invocation result with response headers.
     */
    protected function fakeBedrockInvokeWithHeaders(array $body, array $headers): BedrockRuntimeClient
    {
        return $this->bedrockClient(new MockHandler([new Result([
            'body' => Utils::streamFor(json_encode($body)),
            '@metadata' => ['headers' => $headers],
        ])]));
    }

    /**
     * Create a client returning stream events.
     */
    protected function fakeBedrockStream(array $events): BedrockRuntimeClient
    {
        return $this->bedrockClient(new MockHandler([
            new Result(['stream' => $events]),
        ]));
    }

    /**
     * Create a client returning successive streams.
     */
    protected function fakeBedrockStreamSequence(array $eventLists): BedrockRuntimeClient
    {
        return $this->bedrockClient(new MockHandler(array_map(
            fn (array $events): Result => new Result(['stream' => $events]),
            $eventLists,
        )));
    }

    /**
     * Create a client returning successive results and recording commands.
     */
    protected function fakeBedrockConverseSequence(array $results, array &$requests = []): BedrockRuntimeClient
    {
        return $this->bedrockClient(new MockHandler(array_map(
            function (array $result) use (&$requests): Closure {
                return function (CommandInterface $command) use ($result, &$requests): Result {
                    $requests[] = $command->toArray();

                    return new Result($result);
                };
            },
            $results,
        )));
    }

    /**
     * Run a single generation step and return the parameters sent to the Converse API.
     */
    protected function capturedConverseParameters(?TextGenerationOptions $options = null, array $tools = [], ?string $instructions = 'You are a helpful assistant.'): array
    {
        $captured = [];

        $client = $this->bedrockClient(new MockHandler([function (CommandInterface $command) use (&$captured): Result {
            $captured = $command->toArray();

            return new Result([
                'output' => ['message' => ['content' => [['text' => 'Hello']]]],
                'usage' => ['inputTokens' => 10, 'outputTokens' => 5],
                'stopReason' => 'end_turn',
            ]);
        }]));

        (new TextGenerationLoop($this->gatewayWithClient($client)))->generate(
            $this->bedrockProvider(),
            'anthropic.claude-opus-4-7-v1:0',
            $instructions,
            tools: $tools,
            options: $options,
        );

        return $captured;
    }

    /**
     * Create a client using the supplied handler.
     */
    protected function bedrockClient(MockHandler $mock): BedrockRuntimeClient
    {
        return new BedrockRuntimeClient([
            'region' => 'us-east-1',
            'version' => '2023-09-30',
            'credentials' => false,
            'retries' => 0,
            'handler' => $mock,
        ]);
    }

    /**
     * Create a text gateway using the supplied client.
     */
    protected function gatewayWithClient(BedrockRuntimeClient $client): BedrockTextGateway
    {
        return new class($client) extends BedrockTextGateway {
            /**
             * Create a gateway with the supplied client.
             */
            public function __construct(private BedrockRuntimeClient $stub)
            {
                parent::__construct();
            }

            /**
             * Return the supplied client.
             */
            protected function createBedrockClient(Provider $provider, ?int $timeout = null): BedrockRuntimeClient
            {
                return $this->stub;
            }
        };
    }

    /**
     * Create a gateway retaining client setup with a test handler.
     */
    protected function gatewayWithHandler(MockHandler $mock): BedrockTextGateway
    {
        return new class($mock) extends BedrockTextGateway {
            /**
             * Create a gateway with the supplied handler.
             */
            public function __construct(private MockHandler $mock)
            {
                parent::__construct();
            }

            /**
             * Build a client using the supplied handler.
             */
            protected function createBedrockClient(Provider $provider, ?int $timeout = null): BedrockRuntimeClient
            {
                return tap(parent::createBedrockClient($provider, $timeout), function (BedrockRuntimeClient $client): void {
                    $client->getHandlerList()->setHandler($this->mock);
                });
            }
        };
    }

    /**
     * Create a reranking gateway using the supplied client.
     */
    protected function rerankingGatewayWithClient(BedrockRuntimeClient $client): BedrockRerankingGateway
    {
        return new class($client) extends BedrockRerankingGateway {
            /**
             * Create a gateway with the supplied client.
             */
            public function __construct(private BedrockRuntimeClient $stub)
            {
            }

            /**
             * Return the supplied client.
             */
            protected function createBedrockClient(Provider $provider, ?int $timeout = null): BedrockRuntimeClient
            {
                return $this->stub;
            }
        };
    }

    /**
     * Create a provider without external credentials.
     */
    protected function bedrockProvider(): BedrockProvider
    {
        return new BedrockProvider(
            config: [
                'name' => 'bedrock',
                'driver' => 'bedrock',
                'region' => 'us-east-1',
                'use_default_credential_provider' => false,
            ],
            events: app('events'),
        );
    }

    /**
     * Create a service exception with the requested error details.
     */
    protected function mockBedrockException(string $awsErrorCode, int $statusCode = 400, string $message = 'Bedrock error'): BedrockRuntimeException
    {
        return new class($awsErrorCode, $statusCode, $message) extends BedrockRuntimeException {
            /**
             * Create a service exception.
             */
            public function __construct(
                private string $awsErrorCode,
                private int $httpStatus,
                string $message,
            ) {
                Exception::__construct($message, $httpStatus);
            }

            /**
             * Get the AWS error code.
             */
            public function getAwsErrorCode(): string
            {
                return $this->awsErrorCode;
            }

            /**
             * Get the response status.
             */
            public function getStatusCode(): int
            {
                return $this->httpStatus;
            }
        };
    }

    /**
     * Build a content block start event.
     */
    protected function contentBlockStart(int $index, array $start = []): array
    {
        $payload = ['contentBlockIndex' => $index];

        if ($start !== []) {
            $payload['start'] = $start;
        }

        return ['contentBlockStart' => $payload];
    }

    /**
     * Build a content block delta event.
     */
    protected function contentBlockDelta(int $index, array $delta): array
    {
        return [
            'contentBlockDelta' => [
                'contentBlockIndex' => $index,
                'delta' => $delta,
            ],
        ];
    }

    /**
     * Build a content block stop event.
     */
    protected function contentBlockStop(int $index): array
    {
        return [
            'contentBlockStop' => ['contentBlockIndex' => $index],
        ];
    }

    /**
     * Build a message stop event.
     */
    protected function messageStop(string $stopReason): array
    {
        return [
            'messageStop' => ['stopReason' => $stopReason],
        ];
    }
}
