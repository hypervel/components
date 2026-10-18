<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Hypervel\Ai\Embeddings;
use Hypervel\Ai\Jobs\GenerateEmbeddings;
use Hypervel\Ai\PendingResponses\PendingEmbeddingsGeneration;
use Hypervel\Ai\Prompts\QueuedEmbeddingsPrompt;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;

class EmbeddingsProviderOptionsTest extends TestCase
{
    /**
     * Define the application's environment.
     */
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);
        $app->make('config')->set([
            'ai.providers.cohere.key' => 'test-key',
            'ai.providers.openai.key' => 'test-key',
            'ai.caching.embeddings.store' => 'array',
        ]);
    }

    public function testCacheKeyDiffersByProviderOptionsSoDistinctOptionSetsDoNotCollide(): void
    {
        Http::fake([
            'api.cohere.com/*' => Http::response([
                'embeddings' => ['float' => [[0.1, 0.2, 0.3]]],
                'meta' => ['billed_units' => ['input_tokens' => 1]],
            ]),
        ]);

        Embeddings::for(['Hello'])
            ->cache(3600)
            ->withProviderOptions(['input_type' => 'search_document'])
            ->generate(provider: 'cohere', model: 'embed-v4.0');

        Embeddings::for(['Hello'])
            ->cache(3600)
            ->withProviderOptions(['input_type' => 'search_query'])
            ->generate(provider: 'cohere', model: 'embed-v4.0');

        $observed = collect(Http::recorded())
            ->map(fn (array $pair): mixed => json_decode((string) $pair[0]->body(), true)['input_type'] ?? null)
            ->all();

        $this->assertSame(['search_document', 'search_query'], $observed);
    }

    public function testCacheKeyIsStableForTheSameProviderOptions(): void
    {
        Http::fake([
            'api.cohere.com/*' => Http::response([
                'embeddings' => ['float' => [[0.1, 0.2, 0.3]]],
                'meta' => ['billed_units' => ['input_tokens' => 1]],
            ]),
        ]);

        Embeddings::for(['Hello'])
            ->cache(3600)
            ->withProviderOptions(['input_type' => 'search_query'])
            ->generate(provider: 'cohere', model: 'embed-v4.0');

        Embeddings::for(['Hello'])
            ->cache(3600)
            ->withProviderOptions(['input_type' => 'search_query'])
            ->generate(provider: 'cohere', model: 'embed-v4.0');

        $this->assertCount(1, Http::recorded());
    }

    public function testCacheKeyIsInsensitiveToProviderOptionKeyOrder(): void
    {
        Http::fake([
            'api.cohere.com/*' => Http::response([
                'embeddings' => ['float' => [[0.1, 0.2, 0.3]]],
                'meta' => ['billed_units' => ['input_tokens' => 1]],
            ]),
        ]);

        Embeddings::for(['Hello'])
            ->cache(3600)
            ->withProviderOptions(['input_type' => 'search_query', 'truncate' => 'END'])
            ->generate(provider: 'cohere', model: 'embed-v4.0');

        Embeddings::for(['Hello'])
            ->cache(3600)
            ->withProviderOptions(['truncate' => 'END', 'input_type' => 'search_query'])
            ->generate(provider: 'cohere', model: 'embed-v4.0');

        $this->assertCount(1, Http::recorded());
    }

    public function testClosureResolverReceivesTheResolvedProviderAndAppliesPerProviderOptions(): void
    {
        Http::fake([
            'api.cohere.com/*' => Http::response([
                'embeddings' => ['float' => [[0.1]]],
                'meta' => ['billed_units' => ['input_tokens' => 1]],
            ]),
            'api.openai.com/*' => Http::response([
                'object' => 'list',
                'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1]]],
                'usage' => ['prompt_tokens' => 1],
            ]),
        ]);

        $seen = [];

        Embeddings::for(['Hello'])
            ->withProviderOptions(function (Provider $provider) use (&$seen): array {
                $seen[] = $provider->driver();

                return $provider->driver() === 'cohere'
                    ? ['input_type' => 'search_query']
                    : ['encoding_format' => 'base64'];
            })
            ->generate(provider: 'cohere', model: 'embed-v4.0');

        $this->assertSame(['cohere'], $seen);

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ($body['input_type'] ?? null) === 'search_query';
        });
    }

    public function testClosureProviderOptionsAreNotRecordedOnTheQueuedPromptFake(): void
    {
        Embeddings::fake();

        Embeddings::for(['Hello'])
            ->withProviderOptions(fn (Provider $provider): array => ['input_type' => 'search_query'])
            ->queue(provider: 'cohere', model: 'embed-v4.0');

        Embeddings::assertQueued(
            fn (QueuedEmbeddingsPrompt $prompt): bool => $prompt->providerOptions === [],
        );
    }

    public function testClosureProviderOptionsSurviveQueueSerializationRoundTrip(): void
    {
        Http::fake([
            'api.cohere.com/*' => Http::response([
                'embeddings' => ['float' => [[0.1]]],
                'meta' => ['billed_units' => ['input_tokens' => 1]],
            ]),
        ]);

        $pending = Embeddings::for(['Hello'])
            ->withProviderOptions(fn (Provider $provider): array => ['input_type' => 'search_query']);

        $job = new GenerateEmbeddings($pending, 'cohere', 'embed-v4.0');

        $restored = unserialize(serialize($job));

        $this->assertInstanceOf(PendingEmbeddingsGeneration::class, $restored->pendingEmbeddings);

        $restored->handle();

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ($body['input_type'] ?? null) === 'search_query';
        });
    }

    public function testClosureResolverReturningNullIsTreatedAsNoOptions(): void
    {
        Http::fake([
            'api.cohere.com/*' => Http::response([
                'embeddings' => ['float' => [[0.1]]],
                'meta' => ['billed_units' => ['input_tokens' => 1]],
            ]),
        ]);

        Embeddings::for(['Hello'])
            ->withProviderOptions(fn (): null => null)
            ->generate(provider: 'cohere', model: 'embed-v4.0');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ($body['input_type'] ?? null) === 'search_document';
        });
    }
}
