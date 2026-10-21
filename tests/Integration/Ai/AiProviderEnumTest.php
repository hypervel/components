<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Ai;

use Hypervel\Ai\Audio;
use Hypervel\Ai\Embeddings;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Events\AgentPrompted;
use Hypervel\Ai\Events\AgentStreamed;
use Hypervel\Ai\Events\EmbeddingsGenerated;
use Hypervel\Ai\Events\GeneratingEmbeddings;
use Hypervel\Ai\Responses\AgentResponse;
use Hypervel\Ai\Responses\EmbeddingsResponse;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Ai\Transcription;
use Hypervel\Support\Facades\Event;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Tests\Ai\Fixtures\requiresApiKey;

require_once __DIR__ . '/../../Ai/Fixtures/helpers.php';

class AiProviderEnumTest extends TestCase
{
    /**
     * Require the live services exercised by these tests.
     */
    protected function setUp(): void
    {
        parent::setUp();

        requiresApiKey('GROQ_API_KEY', 'OPENAI_API_KEY');
    }

    /**
     * Clear the response captured across serialized queue callbacks.
     */
    protected function tearDown(): void
    {
        unset($_ENV['__testing.enum_queue_response']);

        parent::tearDown();
    }

    public function testAgentPromptAcceptsAiProviderEnum(): void
    {
        Event::fake();

        $response = (new AssistantAgent)->prompt(
            'What is the name of the PHP framework created by Taylor Otwell?',
            provider: Lab::Groq,
            model: 'openai/gpt-oss-20b',
        );

        $this->assertStringContainsString('Laravel', $response->text);
        $this->assertSame('groq', $response->meta->provider);

        Event::assertDispatched(AgentPrompted::class);
    }

    public function testAgentStreamAcceptsAiProviderEnum(): void
    {
        Event::fake();

        $response = (new AssistantAgent)->stream(
            'What is the name of the PHP framework created by Taylor Otwell?',
            provider: Lab::Groq,
            model: 'openai/gpt-oss-20b',
        );

        $events = [];

        foreach ($response as $event) {
            $events[] = $event;
        }

        $this->assertTrue(collect($events)->whereInstanceOf(TextDelta::class)->isNotEmpty());
        $this->assertStringContainsString('Laravel', $response->text);

        Event::assertDispatched(AgentStreamed::class);
    }

    public function testAgentQueueAcceptsAiProviderEnum(): void
    {
        (new AssistantAgent)->queue(
            'What is the name of the PHP framework created by Taylor Otwell?',
            provider: Lab::Groq,
            model: 'openai/gpt-oss-20b',
        )->then(function (AgentResponse $response): void {
            $_ENV['__testing.enum_queue_response'] = $response;
        });

        $response = $_ENV['__testing.enum_queue_response'];

        $this->assertStringContainsString('Laravel', $response->text);
    }

    public function testAgentPromptAcceptsArrayOfAiProviderEnumValuesForFailover(): void
    {
        $response = (new AssistantAgent)->prompt(
            'What is the name of the PHP framework created by Taylor Otwell?',
            provider: [Lab::Groq],
            model: 'openai/gpt-oss-20b',
        );

        $this->assertStringContainsString('Laravel', $response->text);
        $this->assertSame('groq', $response->meta->provider);
    }

    public function testEmbeddingsGenerateAcceptsAiProviderEnum(): void
    {
        Event::fake();

        $response = Embeddings::for(['I love to watch Star Trek.'])
            ->generate(provider: Lab::OpenAI);

        $this->assertInstanceOf(EmbeddingsResponse::class, $response);
        $this->assertCount(1536, $response->embeddings[0]);
        $this->assertSame('openai', $response->meta->provider);

        Event::assertDispatched(GeneratingEmbeddings::class);
        Event::assertDispatched(EmbeddingsGenerated::class);
    }

    public function testAudioGenerateAcceptsAiProviderEnum(): void
    {
        $response = Audio::of('Hello there! How are you today?')
            ->generate(provider: Lab::OpenAI);

        $this->assertSame('openai', $response->meta->provider);
    }

    public function testTranscriptionGenerateAcceptsAiProviderEnum(): void
    {
        $audio = Audio::of('Hello there! How are you today?')->generate();

        $transcription = Transcription::of($audio->audio)
            ->generate(provider: Lab::OpenAI);

        $this->assertStringContainsString('how are you today', strtolower((string) $transcription));
    }
}
