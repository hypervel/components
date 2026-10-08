<?php

declare(strict_types=1);

namespace Hypervel\Ai;

use Closure;
use Hypervel\Ai\Concerns\InteractsWithFakeAgents;
use Hypervel\Ai\Concerns\InteractsWithFakeAudio;
use Hypervel\Ai\Concerns\InteractsWithFakeClassification;
use Hypervel\Ai\Concerns\InteractsWithFakeEmbeddings;
use Hypervel\Ai\Concerns\InteractsWithFakeFiles;
use Hypervel\Ai\Concerns\InteractsWithFakeImages;
use Hypervel\Ai\Concerns\InteractsWithFakeReranking;
use Hypervel\Ai\Concerns\InteractsWithFakeStores;
use Hypervel\Ai\Concerns\InteractsWithFakeTranscriptions;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\Providers\AudioProvider;
use Hypervel\Ai\Contracts\Providers\ClassificationProvider;
use Hypervel\Ai\Contracts\Providers\EmbeddingProvider;
use Hypervel\Ai\Contracts\Providers\FileProvider;
use Hypervel\Ai\Contracts\Providers\ImageProvider;
use Hypervel\Ai\Contracts\Providers\RerankingProvider;
use Hypervel\Ai\Contracts\Providers\StoreProvider;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Contracts\Providers\TranscriptionProvider;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Gateway\Anthropic\AnthropicGateway;
use Hypervel\Ai\Gateway\Gemini\GeminiGateway;
use Hypervel\Ai\Gateway\OpenAi\OpenAiGateway;
use Hypervel\Ai\Providers\AnthropicProvider;
use Hypervel\Ai\Providers\AzureOpenAiProvider;
use Hypervel\Ai\Providers\BedrockProvider;
use Hypervel\Ai\Providers\CohereProvider;
use Hypervel\Ai\Providers\DeepSeekProvider;
use Hypervel\Ai\Providers\ElevenLabsProvider;
use Hypervel\Ai\Providers\GeminiProvider;
use Hypervel\Ai\Providers\GroqProvider;
use Hypervel\Ai\Providers\JinaProvider;
use Hypervel\Ai\Providers\MistralProvider;
use Hypervel\Ai\Providers\OllamaProvider;
use Hypervel\Ai\Providers\OpenAiCompatibleProvider;
use Hypervel\Ai\Providers\OpenAiProvider;
use Hypervel\Ai\Providers\OpenRouterProvider;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Providers\TypeSafeProvider;
use Hypervel\Ai\Providers\VoyageAiProvider;
use Hypervel\Ai\Providers\XaiProvider;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Support\MultipleInstanceManager;
use InvalidArgumentException;
use LogicException;

class AiManager extends MultipleInstanceManager
{
    use InteractsWithFakeAgents;
    use InteractsWithFakeAudio;
    use InteractsWithFakeClassification;
    use InteractsWithFakeEmbeddings;
    use InteractsWithFakeFiles;
    use InteractsWithFakeImages;
    use InteractsWithFakeReranking;
    use InteractsWithFakeStores;
    use InteractsWithFakeTranscriptions;

    protected const string ON_DEMAND_PROVIDERS_CONTEXT_KEY = '__ai.on_demand_providers';

    /** @var null|Closure(string, array): array */
    protected ?Closure $providerConfigResolver = null;

    protected ?string $conversationPartitionColumn = null;

    /** @var null|Closure(): (null|int|string) */
    protected ?Closure $conversationPartitionResolver = null;

    /** @var null|Closure(): ?string */
    protected ?Closure $embeddingsCacheScopeResolver = null;

    /** @var null|Closure(): ?Closure */
    protected ?Closure $contextCapture = null;

    protected bool $operationsStarted = false;

    /**
     * Build an on-demand provider instance from the given configuration.
     *
     * @throws InvalidArgumentException
     */
    public function build(array $config): Provider
    {
        $name = $config['name'] ?? 'ondemand_' . hash('sha256', json_encode($config, JSON_THROW_ON_ERROR));

        if ($this->config->has("ai.providers.{$name}") || Lab::tryFrom($name) !== null) {
            throw new InvalidArgumentException("The provider name [{$name}] is already taken.");
        }

        $providers = CoroutineContext::get(self::ON_DEMAND_PROVIDERS_CONTEXT_KEY, []);
        $providers[$name] = ['config' => [...$config, 'ondemand' => true]];
        CoroutineContext::set(self::ON_DEMAND_PROVIDERS_CONTEXT_KEY, $providers);

        return $this->instance($name);
    }

    /**
     * Get a provider without retaining operation-owned credentials in the worker.
     */
    public function instance(Provider|string|null $name = null): mixed
    {
        $this->operationsStarted = true;

        if ($name instanceof Provider) {
            return $name;
        }

        $name = $name === null || $name === '' ? $this->getDefaultInstance() : $name;
        $providers = CoroutineContext::get(self::ON_DEMAND_PROVIDERS_CONTEXT_KEY, []);

        if (isset($providers[$name])) {
            if (! isset($providers[$name]['instance'])) {
                $instance = $this->resolve($name);
                $providers = CoroutineContext::get(self::ON_DEMAND_PROVIDERS_CONTEXT_KEY, []);
                $providers[$name]['instance'] = $instance;
                CoroutineContext::set(self::ON_DEMAND_PROVIDERS_CONTEXT_KEY, $providers);
            }

            return $providers[$name]['instance'];
        }

        if ($this->providerConfigResolver !== null) {
            return $this->resolve($name);
        }

        return parent::instance($name);
    }

    /**
     * Forget resolved providers while retaining their configuration.
     *
     * Boot or tests only for configured providers. Their instances are shared
     * by all requests; on-demand providers belong to the current operation.
     */
    public function forgetInstance(array|string|null $name = null): static
    {
        $name ??= $this->getDefaultInstance();
        $providers = CoroutineContext::get(self::ON_DEMAND_PROVIDERS_CONTEXT_KEY, []);

        foreach ((array) $name as $instanceName) {
            unset($providers[$instanceName]['instance']);
        }

        CoroutineContext::set(self::ON_DEMAND_PROVIDERS_CONTEXT_KEY, $providers);

        return parent::forgetInstance($name);
    }

    /**
     * Remove a resolved provider.
     *
     * Boot or tests only for configured providers. Their instances are shared
     * by all requests; on-demand providers belong to the current operation.
     */
    public function purge(?string $name = null): void
    {
        $this->forgetInstance($name);
    }

    /**
     * Get an audio provider instance by name.
     *
     * @throws LogicException
     */
    public function audioProvider(Provider|string|null $name = null): AudioProvider
    {
        return $this->ensureProviderSupports(AudioProvider::class, 'audio generation', $name);
    }

    /**
     * Get an audio provider instance, using a fake gateway if audio is faked.
     *
     * @throws LogicException
     */
    public function fakeableAudioProvider(Provider|string|null $name = null): AudioProvider
    {
        $provider = $this->audioProvider($name);

        return $this->audioIsFaked()
            ? (clone $provider)->useAudioGateway($this->fakeAudioGateway())
            : $provider;
    }

    /**
     * Get a classification provider instance by name.
     *
     * @throws LogicException
     */
    public function classificationProvider(Provider|string|null $name = null): ClassificationProvider
    {
        return $this->ensureProviderSupports(ClassificationProvider::class, 'classification', $name);
    }

    /**
     * Get a classification provider instance, using a fake gateway if classification is faked.
     *
     * @throws LogicException
     */
    public function fakeableClassificationProvider(Provider|string|null $name = null): ClassificationProvider
    {
        $provider = $this->classificationProvider($name);

        return $this->classificationIsFaked()
            ? (clone $provider)->useClassificationGateway($this->fakeClassificationGateway())
            : $provider;
    }

    /**
     * Get an embedding provider instance by name.
     *
     * @throws LogicException
     */
    public function embeddingProvider(Provider|string|null $name = null): EmbeddingProvider
    {
        return $this->ensureProviderSupports(EmbeddingProvider::class, 'embedding generation', $name);
    }

    /**
     * Get an embedding provider instance, using a fake gateway if embeddings are faked.
     *
     * @throws LogicException
     */
    public function fakeableEmbeddingProvider(Provider|string|null $name = null): EmbeddingProvider
    {
        $provider = $this->embeddingProvider($name);

        return $this->embeddingsAreFaked()
            ? (clone $provider)->useEmbeddingGateway($this->fakeEmbeddingGateway())
            : $provider;
    }

    /**
     * Get a reranking provider instance by name.
     *
     * @throws LogicException
     */
    public function rerankingProvider(Provider|string|null $name = null): RerankingProvider
    {
        return $this->ensureProviderSupports(RerankingProvider::class, 'reranking', $name);
    }

    /**
     * Get a reranking provider instance, using a fake gateway if reranking is faked.
     *
     * @throws LogicException
     */
    public function fakeableRerankingProvider(Provider|string|null $name = null): RerankingProvider
    {
        $provider = $this->rerankingProvider($name);

        return $this->rerankingIsFaked()
            ? (clone $provider)->useRerankingGateway($this->fakeRerankingGateway())
            : $provider;
    }

    /**
     * Get an image provider instance by name.
     *
     * @throws LogicException
     */
    public function imageProvider(Provider|string|null $name = null): ImageProvider
    {
        return $this->ensureProviderSupports(ImageProvider::class, 'image generation', $name);
    }

    /**
     * Get an image provider instance, using a fake gateway if images are faked.
     *
     * @throws LogicException
     */
    public function fakeableImageProvider(Provider|string|null $name = null): ImageProvider
    {
        $provider = $this->imageProvider($name);

        return $this->imagesAreFaked()
            ? (clone $provider)->useImageGateway($this->fakeImageGateway())
            : $provider;
    }

    /**
     * Get a text provider instance by name.
     *
     * @throws LogicException
     */
    public function textProvider(Provider|string|null $name = null): TextProvider
    {
        return $this->ensureProviderSupports(TextProvider::class, 'text generation', $name);
    }

    /**
     * Get a provider instance for an agent by name.
     *
     * @throws LogicException
     */
    public function textProviderFor(Agent $agent, Provider|string|null $name = null): TextProvider
    {
        $provider = $this->textProvider($name);

        return $this->hasFakeGatewayFor($agent)
            ? (clone $provider)->useTextGateway($this->fakeGatewayFor($agent))
            : $provider;
    }

    /**
     * Get a transcription provider instance by name.
     *
     * @throws LogicException
     */
    public function transcriptionProvider(Provider|string|null $name = null): TranscriptionProvider
    {
        return $this->ensureProviderSupports(TranscriptionProvider::class, 'transcription generation', $name);
    }

    /**
     * Get a transcription provider instance, using a fake gateway if transcriptions are faked.
     *
     * @throws LogicException
     */
    public function fakeableTranscriptionProvider(Provider|string|null $name = null): TranscriptionProvider
    {
        $provider = $this->transcriptionProvider($name);

        return $this->transcriptionsAreFaked()
            ? (clone $provider)->useTranscriptionGateway($this->fakeTranscriptionGateway())
            : $provider;
    }

    /**
     * Get a file provider instance by name.
     *
     * @throws LogicException
     */
    public function fileProvider(Provider|string|null $name = null): FileProvider
    {
        return $this->ensureProviderSupports(FileProvider::class, 'file management', $name);
    }

    /**
     * Get a file provider instance, using a fake gateway if files are faked.
     *
     * @throws LogicException
     */
    public function fakeableFileProvider(Provider|string|null $name = null): FileProvider
    {
        $provider = $this->fileProvider($name);

        return $this->filesAreFaked()
            ? (clone $provider)->useFileGateway($this->fakeFileGateway())
            : $provider;
    }

    /**
     * Get a store provider instance by name.
     *
     * @throws LogicException
     */
    public function storeProvider(Provider|string|null $name = null): StoreProvider
    {
        return $this->ensureProviderSupports(StoreProvider::class, 'store management', $name);
    }

    /**
     * Get a store provider instance, using a fake gateway if stores are faked.
     *
     * @throws LogicException
     */
    public function fakeableStoreProvider(Provider|string|null $name = null): StoreProvider
    {
        $provider = $this->storeProvider($name);

        return $this->storesAreFaked()
            ? (clone $provider)->useStoreGateway($this->fakeStoreGateway())
            : $provider;
    }

    /**
     * Create an Anthropic powered instance.
     */
    public function createAnthropicDriver(array $config): AnthropicProvider
    {
        return new AnthropicProvider(
            new AnthropicGateway($this->app->make(Dispatcher::class)),
            $config,
            $this->app->make(Dispatcher::class)
        );
    }

    /**
     * Create an Azure OpenAI powered instance.
     */
    public function createAzureDriver(array $config): AzureOpenAiProvider
    {
        return new AzureOpenAiProvider(
            $config,
            $this->app->make(Dispatcher::class)
        );
    }

    /**
     * Create an AWS Bedrock powered instance.
     */
    public function createBedrockDriver(array $config): BedrockProvider
    {
        return new BedrockProvider(
            $config,
            $this->app->make(Dispatcher::class)
        );
    }

    /**
     * Create a Cohere powered instance.
     */
    public function createCohereDriver(array $config): CohereProvider
    {
        return new CohereProvider(
            $config,
            $this->app->make(Dispatcher::class)
        );
    }

    /**
     * Create a DeepSeek powered instance.
     */
    public function createDeepseekDriver(array $config): DeepSeekProvider
    {
        return new DeepSeekProvider(
            $config,
            $this->app->make(Dispatcher::class)
        );
    }

    /**
     * Create an ElevenLabs powered instance.
     */
    public function createElevenDriver(array $config): ElevenLabsProvider
    {
        return new ElevenLabsProvider(
            $config,
            $this->app->make(Dispatcher::class)
        );
    }

    /**
     * Create a Gemini powered instance.
     */
    public function createGeminiDriver(array $config): GeminiProvider
    {
        return new GeminiProvider(
            new GeminiGateway($this->app->make(Dispatcher::class)),
            $config,
            $this->app->make(Dispatcher::class)
        );
    }

    /**
     * Create a Groq powered instance.
     */
    public function createGroqDriver(array $config): GroqProvider
    {
        return new GroqProvider(
            $config,
            $this->app->make(Dispatcher::class)
        );
    }

    /**
     * Create a Jina powered instance.
     */
    public function createJinaDriver(array $config): JinaProvider
    {
        return new JinaProvider(
            $config,
            $this->app->make(Dispatcher::class)
        );
    }

    /**
     * Create a Mistral AI powered instance.
     */
    public function createMistralDriver(array $config): MistralProvider
    {
        return new MistralProvider(
            $config,
            $this->app->make(Dispatcher::class)
        );
    }

    /**
     * Create an Ollama powered instance.
     */
    public function createOllamaDriver(array $config): OllamaProvider
    {
        return new OllamaProvider(
            $config,
            $this->app->make(Dispatcher::class)
        );
    }

    /**
     * Create an OpenAI powered instance.
     */
    public function createOpenaiDriver(array $config): OpenAiProvider
    {
        return new OpenAiProvider(
            new OpenAiGateway($this->app->make(Dispatcher::class)),
            $config,
            $this->app->make(Dispatcher::class)
        );
    }

    /**
     * Create an OpenAI-compatible powered instance.
     */
    public function createOpenaiCompatibleDriver(array $config): OpenAiCompatibleProvider
    {
        return new OpenAiCompatibleProvider(
            $config,
            $this->app->make(Dispatcher::class)
        );
    }

    /**
     * Create an OpenRouter powered instance.
     */
    public function createOpenrouterDriver(array $config): OpenRouterProvider
    {
        return new OpenRouterProvider(
            $config,
            $this->app->make(Dispatcher::class)
        );
    }

    /**
     * Create a TypeSafe powered instance.
     */
    public function createTypesafeDriver(array $config): TypeSafeProvider
    {
        return new TypeSafeProvider(
            $config,
            $this->app->make(Dispatcher::class)
        );
    }

    /**
     * Create a VoyageAI powered instance.
     */
    public function createVoyageaiDriver(array $config): VoyageAiProvider
    {
        return new VoyageAiProvider(
            $config,
            $this->app->make(Dispatcher::class)
        );
    }

    /**
     * Create an xAI powered instance.
     */
    public function createXaiDriver(array $config): XaiProvider
    {
        return new XaiProvider(
            $config,
            $this->app->make(Dispatcher::class),
        );
    }

    /**
     * Get a provider instance by name, ensuring it implements the given capability contract.
     *
     * @template TProvider
     *
     * @param class-string<TProvider> $contract
     * @return TProvider
     *
     * @throws LogicException
     */
    protected function ensureProviderSupports(string $contract, string $capability, Provider|string|null $name): mixed
    {
        $instance = $this->instance($name);

        if (! $instance instanceof $contract) {
            throw new LogicException('Provider [' . $instance::class . "] does not support {$capability}.");
        }

        return $instance;
    }

    /**
     * Get the default instance name.
     */
    public function getDefaultInstance(): string
    {
        $default = $this->config->get('ai.default');

        if ($default instanceof Lab) {
            return $default->value;
        }

        if (is_array($default)) {
            throw new InvalidArgumentException('The "ai.default" config value must be a string provider name or a Lab enum, not an array.');
        }

        return $default;
    }

    /**
     * Set the default instance name.
     *
     * Boot-only. This changes the worker's default provider for every request.
     */
    public function setDefaultInstance(string $name): void
    {
        $this->config->set('ai.default', $name);
    }

    /**
     * Get the instance specific configuration.
     */
    public function getInstanceConfig(string $name): array
    {
        $this->operationsStarted = true;
        $providers = CoroutineContext::get(self::ON_DEMAND_PROVIDERS_CONTEXT_KEY, []);
        $config = $providers[$name]['config'] ?? $this->config->get('ai.providers.' . $name);

        if ($config === null && str_starts_with($name, 'ondemand_')) {
            throw new InvalidArgumentException("On-demand provider [{$name}] was not built in this operation. Build it where the work runs, such as the agent's provider() method.");
        }

        $config ??= ['driver' => $name];

        if (! isset($providers[$name]) && $this->providerConfigResolver !== null) {
            $config = ($this->providerConfigResolver)($name, $config);
        }

        if ($config['driver'] instanceof Lab) {
            $config['driver'] = $config['driver']->value;
        }

        $config['name'] = $name;

        return $config;
    }

    /**
     * Configure the provider configuration resolver.
     *
     * Boot-only. The callback is shared by every subsequent AI operation.
     *
     * @param Closure(string, array): array $resolver
     */
    public function resolveProviderConfigUsing(Closure $resolver): void
    {
        if ($this->operationsStarted || $this->providerConfigResolver !== null) {
            throw new LogicException('The AI provider configuration resolver must be registered once before using AI.');
        }

        $this->providerConfigResolver = $resolver;
    }

    /**
     * Configure the conversation row partition resolver.
     *
     * Boot-only. The column and callback apply to every subsequent conversation operation.
     *
     * @param Closure(): (null|int|string) $resolver
     */
    public function resolveConversationPartitionUsing(string $column, Closure $resolver): void
    {
        if ($this->operationsStarted || $this->conversationPartitionResolver !== null) {
            throw new LogicException('The AI conversation partition resolver must be registered once before using AI.');
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $column) !== 1) {
            throw new InvalidArgumentException('The AI conversation partition column must be a simple SQL identifier.');
        }

        $this->conversationPartitionColumn = $column;
        $this->conversationPartitionResolver = $resolver;
    }

    /**
     * Get the configured conversation partition column.
     */
    public function conversationPartitionColumn(): ?string
    {
        $this->operationsStarted = true;

        return $this->conversationPartitionColumn;
    }

    /**
     * Resolve the current conversation partition.
     */
    public function conversationPartition(): int|string|null
    {
        $this->operationsStarted = true;

        if ($this->conversationPartitionResolver === null) {
            return null;
        }

        $partition = ($this->conversationPartitionResolver)();

        if ($partition === null || $partition === '') {
            throw new LogicException('The AI conversation partition could not be resolved.');
        }

        return $partition;
    }

    /**
     * Configure the embeddings cache scope resolver.
     *
     * Boot-only. The callback applies to every subsequent embeddings cache operation.
     *
     * @param Closure(): ?string $resolver
     */
    public function resolveEmbeddingsCacheScopeUsing(Closure $resolver): void
    {
        if ($this->operationsStarted || $this->embeddingsCacheScopeResolver !== null) {
            throw new LogicException('The AI embeddings cache scope resolver must be registered once before using AI.');
        }

        $this->embeddingsCacheScopeResolver = $resolver;
    }

    /**
     * Resolve the current embeddings cache scope.
     */
    public function embeddingsCacheScope(): ?string
    {
        $this->operationsStarted = true;
        $scope = $this->embeddingsCacheScopeResolver === null ? null : ($this->embeddingsCacheScopeResolver)();

        if ($scope === '') {
            throw new LogicException('The AI embeddings cache scope must not be empty.');
        }

        return $scope;
    }

    /**
     * Configure context capture for deferred operations.
     *
     * Boot-only. The callback is shared by every subsequent AI operation.
     *
     * @param Closure(): ?Closure $capture
     */
    public function captureContextUsing(Closure $capture): void
    {
        if ($this->operationsStarted || $this->contextCapture !== null) {
            throw new LogicException('The AI context capture callback must be registered once before using AI.');
        }

        $this->contextCapture = $capture;
    }

    /**
     * Capture a runner for the current operation's context.
     *
     * @return null|Closure(Closure): mixed
     */
    public function captureContext(): ?Closure
    {
        $this->operationsStarted = true;

        return $this->contextCapture === null ? null : ($this->contextCapture)();
    }

    /**
     * Flush the on-demand providers built during the current operation.
     */
    public function flushState(): void
    {
        CoroutineContext::forget(self::ON_DEMAND_PROVIDERS_CONTEXT_KEY);
    }
}
