<?php

declare(strict_types=1);

namespace Hypervel\Ai\PendingResponses;

use Hypervel\Ai\Ai;
use Hypervel\Ai\Contracts\Files\HasProviderId;
use Hypervel\Ai\Contracts\Files\StorableFile;
use Hypervel\Ai\Contracts\Providers\EmbeddingProvider;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Events\ProviderFailedOver;
use Hypervel\Ai\Exceptions\EmbeddingsCountMismatchException;
use Hypervel\Ai\Exceptions\FailoverableException;
use Hypervel\Ai\Files\Audio;
use Hypervel\Ai\Files\Document;
use Hypervel\Ai\Files\Image;
use Hypervel\Ai\Files\RemoteAudio;
use Hypervel\Ai\Files\RemoteDocument;
use Hypervel\Ai\Files\RemoteImage;
use Hypervel\Ai\Files\RemoteVideo;
use Hypervel\Ai\Files\Video;
use Hypervel\Ai\Jobs\GenerateEmbeddings;
use Hypervel\Ai\PendingResponses\Concerns\ResolvesProviderOptions;
use Hypervel\Ai\Prompts\QueuedEmbeddingsPrompt;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\Usage;
use Hypervel\Ai\Responses\EmbeddingsResponse;
use Hypervel\Ai\Responses\QueuedEmbeddingsResponse;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Support\Facades\Cache;
use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Traits\Conditionable;
use InvalidArgumentException;

class PendingEmbeddingsGeneration
{
    use Conditionable;
    use ResolvesProviderOptions;

    public const int DEFAULT_CACHE_SECONDS = 60 * 60 * 24 * 30;

    protected ?int $dimensions = null;

    protected ?int $cacheSeconds = null;

    protected ?bool $shouldCache = null;

    protected ?bool $cacheIndividually = null;

    protected int $timeout = 30;

    /**
     * Create a new pending embeddings generation instance.
     *
     * @param array<int, Audio|Document|Image|string|Video> $inputs
     *
     * @throws InvalidArgumentException
     */
    public function __construct(protected array $inputs)
    {
        if (! array_is_list($inputs)) {
            throw new InvalidArgumentException('Inputs to embed must be a list, not an associative array.');
        }

        if (blank($inputs)) {
            throw new InvalidArgumentException('At least one input is required to generate embeddings.');
        }

        foreach ($inputs as $index => $input) {
            if (is_string($input)) {
                if (blank($input)) {
                    throw new InvalidArgumentException("The input at index {$index} must be a non-blank string.");
                }

                continue;
            }

            if (! $input instanceof Image
                && ! $input instanceof Audio
                && ! $input instanceof Document
                && ! $input instanceof Video) {
                throw new InvalidArgumentException("The input at index {$index} must be a string or an image, audio, document, or video file.");
            }
        }
    }

    /**
     * Specify the dimensions for the embeddings.
     */
    public function dimensions(int $dimensions): self
    {
        $this->dimensions = $dimensions;

        return $this;
    }

    /**
     * Enable or disable caching for this embedding request.
     */
    public function cache(?int $seconds = null, ?bool $individually = null): self
    {
        if (! is_null($seconds) && $seconds <= 0) {
            $this->shouldCache = false;
            $this->cacheSeconds = null;
            $this->cacheIndividually = null;

            return $this;
        }

        $this->shouldCache = true;
        $this->cacheSeconds = $seconds ?? Config::integer('ai.caching.embeddings.seconds', self::DEFAULT_CACHE_SECONDS);
        $this->cacheIndividually = $individually ?? $this->cacheIndividually;

        return $this;
    }

    /**
     * Specify the timeout (in seconds) for the embeddings generation.
     */
    public function timeout(int $seconds = 30): self
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * Generate the embeddings.
     *
     * @throws FailoverableException if every configured provider fails to generate the embeddings
     */
    public function generate(Provider|Lab|array|string|null $provider = null, ?string $model = null): EmbeddingsResponse
    {
        $providers = Ai::resolveOnDemandProviders(Provider::providerAndModelPairs(
            $provider ?? Config::get('ai.default_for_embeddings'),
            $model
        ));

        $lastException = null;

        foreach ($providers as [$provider, $model]) {
            $provider = Ai::fakeableEmbeddingProvider($provider);

            $model ??= $provider->defaultEmbeddingsModel();

            $dimensions = $this->dimensions ?: $provider->defaultEmbeddingsDimensions();

            [$providerOptions, $headers] = $this->resolveProviderOptionsAndHeaders($provider);

            // Capture the configured account once; per-request headers remain outside cache identity.
            // Pass the same identity through cache reads and writes instead of rebuilding it as upstream does.
            $identity = $this->shouldCache()
                ? $this->cacheIdentity($provider, $model, $dimensions, $providerOptions)
                : null;

            $provider = $provider->withHeaders($headers);

            try {
                return $identity !== null && $this->shouldCacheIndividually()
                    ? $this->generateWithIndividualCaching($provider, $model, $dimensions, $providerOptions, $identity)
                    : $this->generateWithSharedCaching($provider, $model, $dimensions, $providerOptions, $identity);
            } catch (FailoverableException $e) {
                $lastException = $e;

                if (Event::hasListeners(ProviderFailedOver::class)) {
                    Event::dispatch(new ProviderFailedOver($provider, $model, $e));
                }

                continue;
            }
        }

        throw $lastException;
    }

    /**
     * Generate the embeddings, caching the entire response under a single shared key.
     *
     * @param array<string, mixed> $providerOptions
     * @param null|array{prefix: string, fingerprint: string} $identity null when caching is disabled
     */
    protected function generateWithSharedCaching(EmbeddingProvider $provider, string $model, int $dimensions, array $providerOptions, ?array $identity): EmbeddingsResponse
    {
        if ($identity === null) {
            return $provider->embeddings($this->inputs, $dimensions, $model, $this->timeout, $providerOptions);
        }

        $key = $this->cacheKey($identity);

        if (($cached = $this->generateFromCache($key)) instanceof EmbeddingsResponse) {
            return $cached;
        }

        return tap(
            $provider->embeddings($this->inputs, $dimensions, $model, $this->timeout, $providerOptions),
            fn (EmbeddingsResponse $response) => $this->cacheEmbeddings($key, $response)
        );
    }

    /**
     * Generate the embeddings, caching each input's embedding individually.
     *
     * @param array<string, mixed> $providerOptions
     * @param array{prefix: string, fingerprint: string} $identity
     *
     * @throws EmbeddingsCountMismatchException if the provider returns an embedding count that does not match the input count
     */
    protected function generateWithIndividualCaching(EmbeddingProvider $provider, string $model, int $dimensions, array $providerOptions, array $identity): EmbeddingsResponse
    {
        $keys = array_map(fn (mixed $input): string => $this->individualCacheKey($identity, $input), $this->inputs);
        $cached = $this->cachedIndividualEmbeddings($keys);

        if (count($this->inputs) === count($cached)) {
            return new EmbeddingsResponse(array_values($cached), new Usage, new Meta(
                provider: $provider->name(),
                model: $model,
            ));
        }

        $uncachedInputs = array_diff_key($this->inputs, $cached);

        $response = $provider->embeddings(array_values($uncachedInputs), $dimensions, $model, $this->timeout, $providerOptions);

        if (count($response->embeddings) !== count($uncachedInputs)) {
            throw new EmbeddingsCountMismatchException(count($uncachedInputs), count($response->embeddings));
        }

        $generated = array_combine(array_keys($uncachedInputs), $response->embeddings);

        $this->cacheIndividualEmbeddings($keys, $generated);

        $embeddings = $cached + $generated;

        ksort($embeddings);

        return new EmbeddingsResponse(array_values($embeddings), $response->usage, $response->meta);
    }

    /**
     * Generate the embeddings from a cached response if possible.
     */
    protected function generateFromCache(string $key): ?EmbeddingsResponse
    {
        $response = $this->cacheStore()->get($key);

        if (! is_null($response)) {
            return new EmbeddingsResponse(array_map($this->decodeEmbedding(...), $response['embeddings']), new Usage, new Meta(
                provider: $response['provider'],
                model: $response['model'],
            ));
        }

        return null;
    }

    /**
     * Cache the given embeddings response under a single shared key.
     */
    protected function cacheEmbeddings(string $key, EmbeddingsResponse $response): void
    {
        $this->cacheStore()->put(
            $key,
            [
                'embeddings' => array_map($this->encodeEmbedding(...), $response->embeddings),
                'provider' => $response->meta->provider,
                'model' => $response->meta->model,
            ],
            $this->cacheSeconds ?? Config::integer('ai.caching.embeddings.seconds', self::DEFAULT_CACHE_SECONDS)
        );
    }

    /**
     * Get the individually cached embeddings for the inputs, keyed by input index.
     *
     * @param array<int, string> $keys
     * @return array<int, array<float>>
     */
    protected function cachedIndividualEmbeddings(array $keys): array
    {
        $values = [];

        foreach ($this->cacheStore()->getMultiple(array_unique($keys)) as $key => $value) {
            $values[$key] = $value;
        }

        $embeddings = [];

        foreach ($keys as $index => $key) {
            if (! is_null($values[$key] ?? null)) {
                $embeddings[$index] = $this->decodeEmbedding($values[$key]);
            }
        }

        return $embeddings;
    }

    /**
     * Cache the given embeddings individually, keyed by input index.
     *
     * @param array<int, string> $keys
     * @param array<int, array<float>> $embeddings
     */
    protected function cacheIndividualEmbeddings(array $keys, array $embeddings): void
    {
        $values = [];

        foreach ($embeddings as $index => $embedding) {
            $values[$keys[$index]] = $this->encodeEmbedding($embedding);
        }

        $this->cacheStore()->setMultiple(
            $values,
            $this->cacheSeconds ?? Config::integer('ai.caching.embeddings.seconds', self::DEFAULT_CACHE_SECONDS)
        );
    }

    /**
     * Encode a vector without rounding its floating-point values.
     *
     * @param array<float> $embedding
     */
    protected function encodeEmbedding(array $embedding): string
    {
        return base64_encode(pack('e*', ...$embedding));
    }

    /**
     * Decode a cached vector.
     *
     * @return list<float>
     */
    protected function decodeEmbedding(string $embedding): array
    {
        return array_values(unpack('e*', base64_decode($embedding)));
    }

    /**
     * Resolve the scope and effective provider identity for a cached attempt.
     *
     * @param array<string, mixed> $providerOptions
     * @return array{prefix: string, fingerprint: string}
     */
    protected function cacheIdentity(EmbeddingProvider $provider, string $model, int $dimensions, array $providerOptions): array
    {
        $scope = Ai::embeddingsCacheScope();

        return [
            'prefix' => ($scope === null ? '' : $scope . ':') . 'hypervel-embeddings:v1:',
            'fingerprint' => hash('sha256', json_encode($this->normalizeForFingerprint([
                'name' => $provider->name(),
                'driver' => $provider->driver(),
                'credentials' => $provider->providerCredentials(),
                'configuration' => $provider->additionalConfiguration(),
                'model' => $model,
                'dimensions' => $dimensions,
                'options' => $this->fingerprintProviderOptions($providerOptions),
            ]), JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * Get the shared cache key for the entire embeddings request.
     *
     * @param array{prefix: string, fingerprint: string} $identity
     */
    protected function cacheKey(array $identity): string
    {
        return $identity['prefix'] . hash('sha256', json_encode([
            'identity' => $identity['fingerprint'],
            'inputs' => array_map($this->normalizeInputForCache(...), $this->inputs),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Get the cache key for an individual embeddings input.
     *
     * @param array{prefix: string, fingerprint: string} $identity
     */
    protected function individualCacheKey(array $identity, mixed $input): string
    {
        return $identity['prefix'] . hash('sha256', json_encode([
            'identity' => $identity['fingerprint'],
            'input' => $this->normalizeInputForCache($input),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Fingerprint the normalized provider options.
     *
     * @param array<string, mixed> $providerOptions
     */
    protected function fingerprintProviderOptions(array $providerOptions): string
    {
        if ($providerOptions === []) {
            return '';
        }

        $normalized = $this->normalizeForFingerprint($providerOptions);

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    /**
     * Recursively sort associative keys so the fingerprint is insensitive to key order.
     */
    protected function normalizeForFingerprint(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->normalizeForFingerprint(...), $value);
        }

        ksort($value);

        return array_map($this->normalizeForFingerprint(...), $value);
    }

    /**
     * Normalize an embeddings input into a deterministic cache representation.
     */
    protected function normalizeInputForCache(mixed $input): array
    {
        if (is_string($input)) {
            return [
                'type' => 'text',
                'value' => $input,
            ];
        }

        $type = match (true) {
            $input instanceof Image => 'image',
            $input instanceof Audio => 'audio',
            $input instanceof Document => 'document',
            $input instanceof Video => 'video',
            default => throw new InvalidArgumentException('Unsupported embeddings input type [' . get_debug_type($input) . ']'),
        };

        return match (true) {
            $input instanceof HasProviderId => [
                'type' => $type,
                'source' => 'provider',
                'id' => $input->id(),
                'name' => $input->name(),
            ],
            $input instanceof RemoteImage,
            $input instanceof RemoteAudio,
            $input instanceof RemoteDocument,
            $input instanceof RemoteVideo => [
                'type' => $type,
                'source' => 'remote',
                'url' => $input->url,
                'mime' => $input->declaredMimeType(),
                'name' => $input->name(),
            ],
            $input instanceof StorableFile => [
                'type' => $type,
                'source' => 'content',
                'hash' => hash('sha256', $input->content()),
                'mime' => $input->mimeType(),
                'name' => $input->name(),
            ],
            default => throw new InvalidArgumentException('Unsupported embeddings input type [' . get_debug_type($input) . ']'),
        };
    }

    /**
     * Queue the generation of the embeddings.
     */
    public function queue(Provider|Lab|array|string|null $provider = null, ?string $model = null): QueuedEmbeddingsResponse
    {
        if (Ai::embeddingsAreFaked()) {
            Ai::recordEmbeddingsGeneration(
                new QueuedEmbeddingsPrompt(
                    $this->inputs,
                    $this->dimensions,
                    $provider,
                    $model,
                    $this->timeout,
                    $this->queuedProviderOptions(),
                )
            );
        }

        return new QueuedEmbeddingsResponse(
            GenerateEmbeddings::dispatch($this, $provider, $model),
        );
    }

    /**
     * Get the cache store for embeddings.
     */
    protected function cacheStore(): CacheRepository
    {
        return Cache::store(config('ai.caching.embeddings.store'));
    }

    /**
     * Determine if embeddings should be cached.
     */
    protected function shouldCache(): bool
    {
        if (! is_null($this->shouldCache)) {
            return $this->shouldCache;
        }

        return Config::boolean('ai.caching.embeddings.cache', false);
    }

    /**
     * Determine if embeddings should be cached individually per input.
     */
    protected function shouldCacheIndividually(): bool
    {
        if (! $this->shouldCache()) {
            return false;
        }

        return $this->cacheIndividually
            ?? Config::boolean('ai.caching.embeddings.individually', true);
    }
}
