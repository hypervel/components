<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Closure;
use Hypervel\Ai\Ai;
use Hypervel\Ai\Contracts\Gateway\Gateway;
use Hypervel\Ai\Contracts\Providers\EmbeddingProvider;
use Hypervel\Ai\Embeddings;
use Hypervel\Ai\Exceptions\EmbeddingsCountMismatchException;
use Hypervel\Ai\PendingResponses\PendingEmbeddingsGeneration;
use Hypervel\Ai\Providers\Concerns\GeneratesEmbeddings;
use Hypervel\Ai\Providers\Concerns\HasEmbeddingGateway;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\Usage;
use Hypervel\Ai\Responses\EmbeddingsResponse;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;

function fakeCohereEmbedding(string $text): array
{
    return [crc32($text), 0.5];
}

class EmbeddingsCacheTest extends TestCase
{
    /**
     * Configure the embedding provider and cache.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set([
            'ai.providers.cohere' => ['driver' => 'cohere', 'key' => 'test-key'],
            'ai.providers.cache-test' => ['driver' => 'cache-test', 'key' => 'test-key'],
            'ai.default_for_embeddings' => 'cohere',
            'ai.caching.embeddings.store' => 'array',
            'cache.stores.array.serialize' => true,
        ]);
    }

    /**
     * Prepare the embedding response fake.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            'api.cohere.com/*' => fn (Request $request) => Http::response([
                'embeddings' => ['float' => array_map(fakeCohereEmbedding(...), $request->data()['texts'])],
                'meta' => ['billed_units' => ['input_tokens' => count($request->data()['texts'])]],
            ]),
        ]);
    }

    public function testCacheIsUsedWhenEnabledExplicitly(): void
    {
        Embeddings::for(['Hello'])->cache(3600)->generate(provider: 'cohere', model: 'embed-v4.0');
        Embeddings::for(['Hello'])->cache(3600)->generate(provider: 'cohere', model: 'embed-v4.0');

        $this->assertCount(1, Http::recorded());
    }

    public function testCacheIsUsedWhenEnabledGloballyViaConfig(): void
    {
        config(['ai.caching.embeddings.cache' => true]);

        Embeddings::for(['Hello'])->generate(provider: 'cohere', model: 'embed-v4.0');
        Embeddings::for(['Hello'])->generate(provider: 'cohere', model: 'embed-v4.0');

        $this->assertCount(1, Http::recorded());
    }

    public function testZeroCacheSecondsBypassesAnExistingCachedEntry(): void
    {
        Embeddings::for(['Hello'])->cache(3600)->generate(provider: 'cohere', model: 'embed-v4.0');

        $this->assertCount(1, Http::recorded());

        Embeddings::for(['Hello'])->cache(0)->generate(provider: 'cohere', model: 'embed-v4.0');

        $this->assertCount(2, Http::recorded());
    }

    public function testNegativeCacheSecondsBypassesAnExistingCachedEntry(): void
    {
        Embeddings::for(['Hello'])->cache(3600)->generate(provider: 'cohere', model: 'embed-v4.0');

        $this->assertCount(1, Http::recorded());

        Embeddings::for(['Hello'])->cache(-1)->generate(provider: 'cohere', model: 'embed-v4.0');

        $this->assertCount(2, Http::recorded());
    }

    public function testToEmbeddingsHonorsCacheFalseEvenWhenEnabledGlobally(): void
    {
        config(['ai.caching.embeddings.cache' => true]);

        str('hello world')->toEmbeddings(provider: 'cohere', model: 'embed-v4.0', cache: false);
        str('hello world')->toEmbeddings(provider: 'cohere', model: 'embed-v4.0', cache: false);

        $this->assertCount(2, Http::recorded());
    }

    public function testInputsThatDifferOnlyInTheirBoundariesDoNotShareACacheEntry(): void
    {
        Embeddings::for(['a-b', 'c'])->cache(3600)->generate(provider: 'cohere', model: 'embed-v4.0');
        Embeddings::for(['a', 'b-c'])->cache(3600)->generate(provider: 'cohere', model: 'embed-v4.0');

        $this->assertCount(2, Http::recorded());
    }

    public function testIdenticalStringInputsStillShareACacheEntry(): void
    {
        Embeddings::for(['a-b', 'c'])->cache(3600)->generate(provider: 'cohere', model: 'embed-v4.0');
        Embeddings::for(['a-b', 'c'])->cache(3600)->generate(provider: 'cohere', model: 'embed-v4.0');

        $this->assertCount(1, Http::recorded());
    }

    public function testReorderedStringInputsDoNotShareACacheEntry(): void
    {
        Embeddings::for(['a', 'b'])->cache(3600, individually: false)->generate(provider: 'cohere', model: 'embed-v4.0');
        Embeddings::for(['b', 'a'])->cache(3600, individually: false)->generate(provider: 'cohere', model: 'embed-v4.0');

        $this->assertCount(2, Http::recorded());
    }

    public function testInputsCanOptOutOfIndividualCaching(): void
    {
        Embeddings::for(['a', 'b'])->cache(3600, individually: false)->generate(provider: 'cohere', model: 'embed-v4.0');
        Embeddings::for(['b'])->cache(3600, individually: false)->generate(provider: 'cohere', model: 'embed-v4.0');

        $this->assertCount(2, Http::recorded());
    }

    public function testEachInputIsCachedIndividuallyWhenOptedIn(): void
    {
        Embeddings::for(['a', 'b'])->cache(3600, individually: true)->generate(provider: 'cohere', model: 'embed-v4.0');

        $response = Embeddings::for(['b'])->cache(3600, individually: true)->generate(provider: 'cohere', model: 'embed-v4.0');

        $this->assertCount(1, Http::recorded());
        $this->assertEquals([fakeCohereEmbedding('b')], $response->embeddings);
    }

    public function testIndividualCachingCanBeEnabledGloballyViaConfig(): void
    {
        config([
            'ai.caching.embeddings.cache' => true,
            'ai.caching.embeddings.individually' => true,
        ]);

        Embeddings::for(['a', 'b'])->generate(provider: 'cohere', model: 'embed-v4.0');
        Embeddings::for(['b'])->generate(provider: 'cohere', model: 'embed-v4.0');

        $this->assertCount(1, Http::recorded());
    }

    public function testIndividualCachingIsEnabledWhenTheConfigValueIsMissing(): void
    {
        config(['ai.caching.embeddings' => [
            'cache' => false,
            'store' => 'array',
        ]]);

        Embeddings::for(['a', 'b'])->cache(3600)->generate(provider: 'cohere', model: 'embed-v4.0');
        Embeddings::for(['b'])->cache(3600)->generate(provider: 'cohere', model: 'embed-v4.0');

        $this->assertCount(1, Http::recorded());
    }

    public function testFullyIndividuallyCachedRequestsAreServedInAnyInputOrderWithoutAProviderCall(): void
    {
        Embeddings::for(['a', 'b'])->cache(3600, individually: true)->generate(provider: 'cohere', model: 'embed-v4.0');

        $response = Embeddings::for(['b', 'a'])->cache(3600, individually: true)->generate(provider: 'cohere', model: 'embed-v4.0');

        $this->assertCount(1, Http::recorded());
        $this->assertSame(0, $response->usage->inputTokens);
        $this->assertSame('cohere', $response->meta->provider);
        $this->assertSame('embed-v4.0', $response->meta->model);
        $this->assertEquals([fakeCohereEmbedding('b'), fakeCohereEmbedding('a')], $response->embeddings);
    }

    public function testPartiallyCachedRequestsOnlyGenerateEmbeddingsForTheUncachedInputs(): void
    {
        Embeddings::for(['a', 'b'])->cache(3600, individually: true)->generate(provider: 'cohere', model: 'embed-v4.0');

        $response = Embeddings::for(['c', 'a', 'd'])->cache(3600, individually: true)->generate(provider: 'cohere', model: 'embed-v4.0');

        $this->assertCount(2, Http::recorded());

        [$request] = Http::recorded()[1];

        $this->assertSame(['c', 'd'], $request->data()['texts']);
        $this->assertSame(2, $response->usage->inputTokens);
        $this->assertEquals([
            fakeCohereEmbedding('c'),
            fakeCohereEmbedding('a'),
            fakeCohereEmbedding('d'),
        ], $response->embeddings);
    }

    public function testEmbeddingsGeneratedByAPartiallyCachedRequestAreCachedIndividually(): void
    {
        Embeddings::for(['a'])->cache(3600, individually: true)->generate(provider: 'cohere', model: 'embed-v4.0');
        Embeddings::for(['a', 'b'])->cache(3600, individually: true)->generate(provider: 'cohere', model: 'embed-v4.0');
        Embeddings::for(['b'])->cache(3600, individually: true)->generate(provider: 'cohere', model: 'embed-v4.0');

        $this->assertCount(2, Http::recorded());
    }

    public function testDuplicateInputsResolveToTheSameIndividualCacheEntry(): void
    {
        $response = Embeddings::for(['a', 'a'])->cache(3600, individually: true)->generate(provider: 'cohere', model: 'embed-v4.0');

        $this->assertEquals([fakeCohereEmbedding('a'), fakeCohereEmbedding('a')], $response->embeddings);

        Embeddings::for(['a'])->cache(3600, individually: true)->generate(provider: 'cohere', model: 'embed-v4.0');

        $this->assertCount(1, Http::recorded());
    }

    public function testIndividualCachingThrowsWhenTheProviderReturnsAMismatchedEmbeddingCount(): void
    {
        Embeddings::fake([
            [[0.1, 0.2, 0.3]],
        ]);

        $this->expectException(EmbeddingsCountMismatchException::class);
        $this->expectExceptionMessage('Provider returned 1 embeddings for 2 inputs.');
        Embeddings::for(['a', 'b'])->cache(3600, individually: true)->generate(provider: 'cohere', model: 'embed-v4.0');
    }

    public function testIndividuallyCachedInputsDoNotShareEntriesWithBatchCachedInputs(): void
    {
        Embeddings::for(['a'])->cache(3600, individually: false)->generate(provider: 'cohere', model: 'embed-v4.0');
        Embeddings::for(['a'])->cache(3600, individually: true)->generate(provider: 'cohere', model: 'embed-v4.0');

        $this->assertCount(2, Http::recorded());
    }

    public function testToEmbeddingsUsesCacheWhenEnabledGlobally(): void
    {
        config(['ai.caching.embeddings.cache' => true]);

        str('hello world')->toEmbeddings(provider: 'cohere', model: 'embed-v4.0');
        str('hello world')->toEmbeddings(provider: 'cohere', model: 'embed-v4.0');

        $this->assertCount(1, Http::recorded());
    }

    #[DataProvider('cacheModes')]
    public function testCustomProviderCachePreservesExactVectorsAndMetadata(bool $individually): void
    {
        $vector = [0.12345678901234567, -0.9876543210987654, PHP_FLOAT_MIN, PHP_FLOAT_MAX, -0.0];
        $calls = 0;
        $this->useCustomProvider(function (array $inputs) use ($vector, &$calls): EmbeddingsResponse {
            ++$calls;

            return new EmbeddingsResponse(array_fill(0, count($inputs), $vector), new Usage(5), new Meta('cache-test', 'embedding-model'));
        });

        $first = Embeddings::for(['hello'])->cache(individually: $individually)->generate('cache-test');
        $cached = Embeddings::for(['hello'])->cache(individually: $individually)->generate('cache-test');

        $this->assertSame($first->embeddings, $cached->embeddings);
        $this->assertSame(pack('e*', ...$vector), pack('e*', ...$cached->first()));
        $this->assertSame(0, $cached->usage->inputTokens);
        $this->assertSame('cache-test', $cached->meta->provider);
        $this->assertSame('embedding-model', $cached->meta->model);
        $this->assertSame(1, $calls);
    }

    public function testDefaultCacheDurationMatchesTheShippedConfiguration(): void
    {
        $this->assertSame(PendingEmbeddingsGeneration::DEFAULT_CACHE_SECONDS, config('ai.caching.embeddings.seconds'));
    }

    #[DataProvider('cacheModes')]
    public function testResolvedAccountsAndScopesDoNotShareEntries(bool $individually): void
    {
        $configuration = ['key' => 'first-key', 'url' => 'https://first.example.com', 'headers' => ['Authorization' => 'first-account']];
        Ai::resolveProviderConfigUsing(static function (string $name, array $base) use (&$configuration): array {
            return array_replace($base, $configuration);
        });
        Ai::resolveEmbeddingsCacheScopeUsing(static fn (): ?string => CoroutineContext::get('ai-test.cache-scope'));
        $calls = 0;
        $this->useCustomProvider(function () use (&$calls): EmbeddingsResponse {
            return new EmbeddingsResponse([[(float) ++$calls]], new Usage(1), new Meta('cache-test', 'embedding-model'));
        });
        $generate = fn (): EmbeddingsResponse => Embeddings::for(['hello'])->cache(individually: $individually)->generate('cache-test');

        $this->assertSame([[1.0]], $generate()->embeddings);
        $configuration['key'] = 'second-key';
        $this->assertSame([[2.0]], $generate()->embeddings);
        $configuration['url'] = 'https://second.example.com';
        $this->assertSame([[3.0]], $generate()->embeddings);
        $configuration['headers']['Authorization'] = 'second-account';
        $this->assertSame([[4.0]], $generate()->embeddings);
        CoroutineContext::set('ai-test.cache-scope', 'account-one');
        $this->assertSame([[5.0]], $generate()->embeddings);
        CoroutineContext::set('ai-test.cache-scope', 'account-two');
        $this->assertSame([[6.0]], $generate()->embeddings);
        CoroutineContext::forget('ai-test.cache-scope');
        $this->assertSame([[4.0]], $generate()->embeddings);
        $this->assertSame(6, $calls);
    }

    /**
     * Provide both supported caching modes.
     */
    public static function cacheModes(): array
    {
        return ['individual' => [true], 'whole response' => [false]];
    }

    public function testSameNameOnDemandProvidersRetainTheirOwnCacheIdentity(): void
    {
        $gateway = m::mock(Gateway::class);
        $gateway->shouldReceive('generateEmbeddings')->twice()->andReturnUsing(
            fn (EmbeddingProvider $provider): EmbeddingsResponse => new EmbeddingsResponse(
                [[$provider->providerCredentials()['key'] === 'first-key' ? 1.0 : 2.0]],
                new Usage(1),
                new Meta($provider->name(), 'embedding-model')
            )
        );
        Ai::extend('cache-test', function (Application $app, array $config) use ($gateway): EmbeddingProvider {
            return new EmbeddingsCacheProvider($gateway, $config, $app->make('events'));
        });
        $first = Ai::build(['name' => 'dynamic', 'driver' => 'cache-test', 'key' => 'first-key']);
        $second = Ai::build(['name' => 'dynamic', 'driver' => 'cache-test', 'key' => 'second-key']);

        foreach ([$first, $second, $first] as $provider) {
            $response = Embeddings::for(['hello'])->cache()->generate($provider);
            $this->assertSame([[$provider === $first ? 1.0 : 2.0]], $response->embeddings);
        }
    }

    public function testDisabledCachingDoesNotResolveCacheScope(): void
    {
        Ai::resolveEmbeddingsCacheScopeUsing(function (): ?string {
            $this->fail('Disabled caching must not resolve a cache scope.');
        });
        $this->useCustomProvider(fn (): EmbeddingsResponse => new EmbeddingsResponse([[0.25]], new Usage(1), new Meta));

        $this->assertSame([[0.25]], Embeddings::for(['hello'])->cache(0)->generate('cache-test')->embeddings);
    }

    /**
     * Register a contract-only provider to exercise cache behavior independently of a vendor gateway.
     */
    protected function useCustomProvider(Closure $generate): void
    {
        Ai::extend('cache-test', function (Application $app, array $config) use ($generate): EmbeddingProvider {
            $provider = m::mock(EmbeddingProvider::class);
            $provider->shouldReceive('name')->andReturn('cache-test');
            $provider->shouldReceive('driver')->andReturn('cache-test');
            $provider->shouldReceive('providerCredentials')->andReturn(['key' => $config['key']]);
            $provider->shouldReceive('additionalConfiguration')->andReturn(array_diff_key($config, array_flip(['name', 'driver', 'key'])));
            $provider->shouldReceive('defaultEmbeddingsModel')->andReturn('embedding-model');
            $provider->shouldReceive('defaultEmbeddingsDimensions')->andReturn(1);
            $provider->shouldReceive('withHeaders')->andReturnSelf();
            $provider->shouldReceive('embeddings')->andReturnUsing($generate);

            return $provider;
        });
    }
}

class EmbeddingsCacheProvider extends Provider implements EmbeddingProvider
{
    use GeneratesEmbeddings;
    use HasEmbeddingGateway;

    /**
     * Get the default embeddings model.
     */
    public function defaultEmbeddingsModel(): string
    {
        return 'embedding-model';
    }

    /**
     * Get the default embeddings dimensions.
     */
    public function defaultEmbeddingsDimensions(): int
    {
        return 1;
    }
}
