<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Generator;
use Hypervel\Ai\AiManager;
use Hypervel\Ai\Contracts\ConversationStore;
use Hypervel\Ai\Events\AgentFailedOver;
use Hypervel\Ai\Events\AgentStreamed;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Ai\Prompts\AgentPrompt;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\StreamableAgentResponse;
use Hypervel\Ai\Responses\StreamedAgentResponse;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Str;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\RememberingAssistantAgent;
use Hypervel\Tests\Ai\Fixtures\ConversationStores\InMemoryConversationStore;
use Hypervel\Tests\Ai\Fixtures\Providers\FakeStreamingProvider;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;

class AgentStreamFailoverTest extends TestCase
{
    public function testStreamFailsOverToNextProviderWhenPrimaryIsRateLimited(): void
    {
        Event::fake();

        config([
            'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
            'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
        ]);

        Http::preventStrayRequests();

        Http::fakeSequence()
            ->push(status: 429)
            ->push(fakeGroqStreamBodyForStreamFailover(), 200);

        $response = (new AssistantAgent)->stream(
            'Hello',
            provider: ['primary', 'backup'],
        );

        $events = [];

        foreach ($response as $event) {
            $events[] = $event;
        }

        $this->assertTrue(collect($events)->whereInstanceOf(TextDelta::class)->isNotEmpty());
        $this->assertSame('Hello', $response->text);

        Event::assertDispatched(AgentFailedOver::class);

        Event::assertDispatched(AgentStreamed::class, fn (AgentStreamed $event): bool => $event->invocationId === $response->invocationId);
    }

    public function testStreamThrowsLastExceptionWhenAllProvidersFail(): void
    {
        Event::fake();

        config([
            'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
            'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
        ]);

        Http::preventStrayRequests();

        Http::fakeSequence()
            ->push(status: 429)
            ->push(status: 429);

        $response = (new AssistantAgent)->stream(
            'Hello',
            provider: ['primary', 'backup'],
        );

        $this->assertThrows(function () use ($response): void {
            foreach ($response as $_);
        }, RateLimitedException::class);
    }

    public function testStreamThenCallbackIsInvokedAfterFailover(): void
    {
        Event::fake();

        config([
            'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
            'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
        ]);

        Http::preventStrayRequests();

        Http::fakeSequence()
            ->push(status: 429)
            ->push(fakeGroqStreamBodyForStreamFailover(), 200);

        $response = (new AssistantAgent)->stream(
            'Hello',
            provider: ['primary', 'backup'],
        );

        $thenResponse = null;

        $response->then(function (StreamedAgentResponse $response) use (&$thenResponse): void {
            $thenResponse = $response;
        });

        foreach ($response as $_);

        $this->assertInstanceOf(StreamedAgentResponse::class, $thenResponse);
        $this->assertSame('Hello', $thenResponse->text);
        $this->assertSame('backup', $thenResponse->meta->provider);
    }

    public function testStreamDoesNotFailOverWhenPrimarySucceeds(): void
    {
        Event::fake();

        config([
            'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
            'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
        ]);

        Http::preventStrayRequests();

        Http::fakeSequence()
            ->push(fakeGroqStreamBodyForStreamFailover(), 200);

        $response = (new AssistantAgent)->stream(
            'Hello',
            provider: ['primary', 'backup'],
        );

        foreach ($response as $_);

        $this->assertSame('Hello', $response->text);

        Event::assertNotDispatched(AgentFailedOver::class);
    }

    public function testSingleProviderStreamDoesNotDispatchFailoverEventWhenRateLimited(): void
    {
        Event::fake();

        config([
            'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
        ]);

        Http::preventStrayRequests();

        Http::fakeSequence()
            ->push(status: 429);

        $response = (new AssistantAgent)->stream(
            'Hello',
            provider: 'primary',
        );

        $this->assertThrows(function () use ($response): void {
            foreach ($response as $_);
        }, RateLimitedException::class);

        Event::assertNotDispatched(AgentFailedOver::class);
    }

    public function testStreamDoesNotFailOverWhenPrimaryEmitsEventThenThrows(): void
    {
        Event::fake();

        $manager = app(AiManager::class);

        $manager->extend('mid_stream_failing', fn (Container $app, array $config): FakeStreamingProvider => new FakeStreamingProvider(
            $config,
            $app->make(Dispatcher::class),
            fn (FakeStreamingProvider $provider, AgentPrompt $prompt): StreamableAgentResponse => new StreamableAgentResponse(
                (string) Str::uuid7(),
                function (): Generator {
                    yield (new TextDelta('m1', 'm1', 'partial', 0))->withInvocationId('inner-fail');

                    throw RateLimitedException::forProvider('mid_stream_failing');
                },
                new Meta($provider->name(), $prompt->model),
            ),
        ));

        $manager->extend('working_backup', fn (Container $app, array $config): FakeStreamingProvider => new FakeStreamingProvider(
            $config,
            $app->make(Dispatcher::class),
            fn (FakeStreamingProvider $provider, AgentPrompt $prompt): StreamableAgentResponse => new StreamableAgentResponse(
                (string) Str::uuid7(),
                function (): Generator {
                    yield (new TextDelta('m2', 'm2', 'World', 0))->withInvocationId('inner-success');
                },
                new Meta($provider->name(), $prompt->model),
            ),
        ));

        config([
            'ai.providers.primary' => ['driver' => 'mid_stream_failing'],
            'ai.providers.backup' => ['driver' => 'working_backup'],
        ]);

        $response = (new AssistantAgent)->stream(
            'Hello',
            provider: ['primary', 'backup'],
        );

        $this->assertThrows(function () use ($response): void {
            foreach ($response as $_);
        }, RateLimitedException::class);

        Event::assertNotDispatched(AgentFailedOver::class);
    }

    public function testStreamDoesNotFailOverWhenPrimaryThrowsNonFailoverableException(): void
    {
        Event::fake();

        $manager = app(AiManager::class);
        $backupStreamed = false;

        $manager->extend('malformed_stream', fn (Container $app, array $config): FakeStreamingProvider => new FakeStreamingProvider(
            $config,
            $app->make(Dispatcher::class),
            fn (FakeStreamingProvider $provider, AgentPrompt $prompt): StreamableAgentResponse => new StreamableAgentResponse(
                (string) Str::uuid7(),
                function (): void {
                    throw new InvalidArgumentException('Malformed stream response.');
                },
                new Meta($provider->name(), $prompt->model),
            ),
        ));

        $manager->extend('backup_after_malformed_stream', fn (Container $app, array $config): FakeStreamingProvider => new FakeStreamingProvider(
            $config,
            $app->make(Dispatcher::class),
            function (FakeStreamingProvider $provider, AgentPrompt $prompt) use (&$backupStreamed): StreamableAgentResponse {
                $backupStreamed = true;

                return new StreamableAgentResponse(
                    (string) Str::uuid7(),
                    function (): Generator {
                        yield (new TextDelta('m2', 'm2', 'World', 0))->withInvocationId('inner-success');
                    },
                    new Meta($provider->name(), $prompt->model),
                );
            },
        ));

        config([
            'ai.providers.primary' => ['driver' => 'malformed_stream'],
            'ai.providers.backup' => ['driver' => 'backup_after_malformed_stream'],
        ]);

        $response = (new AssistantAgent)->stream(
            'Hello',
            provider: ['primary', 'backup'],
        );

        $this->assertThrows(function () use ($response): void {
            foreach ($response as $_);
        }, InvalidArgumentException::class, 'Malformed stream response.');

        $this->assertFalse($backupStreamed);

        Event::assertNotDispatched(AgentFailedOver::class);
    }

    public function testStreamConversationStateSurvivesFailover(): void
    {
        $store = new InMemoryConversationStore;
        $this->app->instance(ConversationStore::class, $store);

        config([
            'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
            'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
        ]);

        Http::preventStrayRequests();

        Http::fakeSequence()
            ->push(status: 429)
            ->push(fakeGroqStreamBodyForStreamFailover(), 200);

        $user = (object) ['id' => 'user-1'];
        $existingConversationId = 'existing-conv-1';

        $response = (new RememberingAssistantAgent)
            ->continue($existingConversationId, $user)
            ->stream('Hello', provider: ['primary', 'backup']);

        $thenResponse = null;

        $response->then(function (StreamedAgentResponse $response) use (&$thenResponse): void {
            $thenResponse = $response;
        });

        foreach ($response as $_);

        $this->assertSame($existingConversationId, $thenResponse->conversationId);
        $this->assertSame($user, $thenResponse->conversationUser);
        $this->assertNotNull($response->userMessageId);
        $this->assertNotNull($response->assistantMessageId);
        $this->assertSame('user', collect($store->messages)->firstWhere('id', $response->userMessageId)['role']);
        $this->assertSame('assistant', collect($store->messages)->firstWhere('id', $response->assistantMessageId)['role']);
    }

    public function testFirstTurnStreamFailoverPersistsItsReservedConversation(): void
    {
        $store = new InMemoryConversationStore;
        $this->app->instance(ConversationStore::class, $store);

        config([
            'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
            'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
        ]);

        Http::preventStrayRequests();

        Http::fakeSequence()
            ->push(status: 429)
            ->push(fakeGroqStreamBodyForStreamFailover(), 200);

        $agent = (new RememberingAssistantAgent)->forUser((object) ['id' => 'user-1']);
        $response = $agent->stream('Hello', provider: ['primary', 'backup']);

        foreach ($response as $_);

        $this->assertCount(1, $store->conversations);
        $this->assertArrayHasKey($agent->currentConversation(), $store->conversations);
        $this->assertSame($agent->currentConversation(), $response->conversationId);
        $this->assertSame([$agent->currentConversation()], collect($store->messages)->pluck('conversation_id')->unique()->all());
    }
}

function fakeGroqStreamBodyForStreamFailover(): string
{
    $chunks = [
        '{"id":"chatcmpl-1","object":"chat.completion.chunk","created":1,"model":"test","choices":[{"index":0,"delta":{"role":"assistant","content":""},"finish_reason":null}]}',
        '{"id":"chatcmpl-1","object":"chat.completion.chunk","created":1,"model":"test","choices":[{"index":0,"delta":{"content":"Hello"},"finish_reason":null}]}',
        '{"id":"chatcmpl-1","object":"chat.completion.chunk","created":1,"model":"test","choices":[{"index":0,"delta":{},"finish_reason":"stop"}],"usage":{"prompt_tokens":5,"completion_tokens":1,"total_tokens":6}}',
    ];

    $body = '';

    foreach ($chunks as $chunk) {
        $body .= "data: {$chunk}\n\n";
    }

    return $body . "data: [DONE]\n\n";
}
