<?php

declare(strict_types=1);

namespace Hypervel\Ai;

use Closure;
use Hypervel\Ai\Agents\SummarizeAgent;
use Hypervel\Ai\Classification\Boolean;
use Hypervel\Ai\Classification\CollectionChoice;
use Hypervel\Ai\Console\Commands\MakeAgentCommand;
use Hypervel\Ai\Console\Commands\MakeAgentMiddlewareCommand;
use Hypervel\Ai\Console\Commands\MakeToolCommand;
use Hypervel\Ai\Contracts\ConversationStore;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\AudioResponse;
use Hypervel\Ai\Responses\Data\BooleanAnswer;
use Hypervel\Ai\Responses\Data\RankedDocument;
use Hypervel\Ai\Storage\DatabaseConversationStore;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Http\Client\Factory;
use Hypervel\Support\Collection;
use Hypervel\Support\ServiceProvider;
use Hypervel\Support\Str;
use Hypervel\Support\Stringable;
use Override;

class AiServiceProvider extends ServiceProvider
{
    /**
     * Register the package's services.
     */
    #[Override]
    public function register(): void
    {
        $this->app->singleton(ConversationStore::class, fn (): DatabaseConversationStore => new DatabaseConversationStore(
            config('ai.conversations.connection'),
        ));

        $this->mergeConfigFrom(__DIR__ . '/../config/ai.php', 'ai');
    }

    /**
     * Get configuration arrays whose entries should be merged by name.
     */
    protected function mergeableOptions(string $name): array
    {
        return $name === 'ai' ? ['providers'] : [];
    }

    /**
     * Bootstrap the package's services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->registerCommands();
            $this->registerPublishing();
        }

        $http = $this->app->make(Factory::class);
        $http->registerConnection(AiManager::HTTP_CONNECTION);

        foreach ($this->app->make(Repository::class)->array('ai.providers') as $name => $provider) {
            $http->registerConnection(AiManager::HTTP_CONNECTION . '.' . $name);
        }

        // Embeddings macro...
        Stringable::macro('toEmbeddings', function (
            Provider|Lab|array|string|null $provider = null,
            ?int $dimensions = null,
            ?string $model = null,
            bool|int|null $cache = null,
            ?int $timeout = null,
            array|Closure $providerOptions = [],
        ): array {
            $request = Embeddings::for([$this->value()]);

            if ($dimensions) {
                $request->dimensions($dimensions);
            }

            if ($cache === false) {
                $request->cache(0);
            } elseif (! is_null($cache)) {
                $request->cache(is_int($cache) ? $cache : null);
            }

            if (! is_null($timeout)) {
                $request->timeout($timeout);
            }

            if (filled($providerOptions)) {
                $request->withProviderOptions($providerOptions);
            }

            return $request->generate(provider: $provider, model: $model)->embeddings[0];
        });

        // Audio macro...
        Stringable::macro('toAudio', function (
            Provider|Lab|array|string|null $provider = null,
            ?string $voice = null,
            ?string $instructions = null,
            ?string $model = null,
            ?int $timeout = null,
        ): AudioResponse {
            $request = Audio::of($this->value());

            if (! is_null($voice)) {
                $request->voice($voice);
            }

            if (! is_null($instructions)) {
                $request->instructions($instructions);
            }

            if (! is_null($timeout)) {
                $request->timeout($timeout);
            }

            return $request->generate(provider: $provider, model: $model);
        });

        // Summarize macros...
        Stringable::macro('summarize', fn (
            int $sentences = 3,
            Provider|Lab|array|string|null $provider = null,
            ?string $model = null,
            ?int $timeout = null,
        ): string => (new SummarizeAgent($sentences))
            ->prompt($this->value(), provider: $provider, model: $model, timeout: $timeout)->text);

        Str::macro('summarize', fn (
            string $value,
            int $sentences = 3,
            Provider|Lab|array|string|null $provider = null,
            ?string $model = null,
            ?int $timeout = null,
        ): string => (new SummarizeAgent($sentences))
            ->prompt($value, provider: $provider, model: $model, timeout: $timeout)->text);

        // Decision macros...
        $decide = function (
            string $value,
            string $question,
            array $criteria,
            float $threshold,
            Provider|Lab|array|string|null $provider,
            ?string $model,
            ?int $timeout,
        ): bool {
            $request = Classification::of($value)->question('decision', new Boolean($question, $criteria ?: null));

            if (! is_null($timeout)) {
                $request->timeout($timeout);
            }

            /** @var BooleanAnswer $answer */
            $answer = $request->classify($provider, $model)->answer('decision');

            return $answer->isTrue($threshold);
        };

        Stringable::macro('decide', fn (
            string $question,
            array $criteria = [],
            float $threshold = 0.5,
            Provider|Lab|array|string|null $provider = null,
            ?string $model = null,
            ?int $timeout = null,
        ): bool => $decide($this->value(), $question, $criteria, $threshold, $provider, $model, $timeout));

        Str::macro('decide', fn (
            string $value,
            string $question,
            array $criteria = [],
            float $threshold = 0.5,
            Provider|Lab|array|string|null $provider = null,
            ?string $model = null,
            ?int $timeout = null,
        ): bool => $decide($value, $question, $criteria, $threshold, $provider, $model, $timeout));

        Collection::macro('decide', fn (
            string $question,
            string|array $text,
            Closure|string|null $by = null,
            Closure|array|string|null $describe = null,
            ?float $threshold = null,
            Provider|Lab|array|string|null $provider = null,
            ?string $model = null,
            ?int $timeout = null,
        ): mixed => (new CollectionChoice($this, $by, $describe))
            ->decide($question, $text, $threshold, $provider, $model, $timeout));

        // Reranking macro...
        Collection::macro('rerank', function (
            Closure|array|string $by,
            string $query,
            ?int $limit = null,
            Provider|Lab|array|string|null $provider = null,
            ?string $model = null,
            int $timeout = 30,
        ): Collection {
            $resolver = match (true) {
                $by instanceof Closure => $by,
                is_array($by) => fn (mixed $item): string|false => json_encode(
                    (new Collection($by))->mapWithKeys(fn (string|int $field): array => [$field => data_get($item, $field)])->all()
                ),
                default => fn (mixed $item): mixed => data_get($item, $by),
            };

            $response = Reranking::of($this->map($resolver)->values()->all())
                ->limit($limit)
                ->timeout($timeout)
                ->rerank($query, $provider, $model);

            $items = $this->values();

            return (new Collection($response->results))->map(
                fn (RankedDocument $result): mixed => $items[$result->index]
            );
        });
    }

    /**
     * Register the package's console commands.
     */
    protected function registerCommands(): void
    {
        $this->commands([
            // ChatCommand::class,
            MakeAgentCommand::class,
            MakeAgentMiddlewareCommand::class,
            MakeToolCommand::class,
        ]);
    }

    /**
     * Register the package's publishable resources.
     */
    protected function registerPublishing(): void
    {
        $this->publishes([
            __DIR__ . '/../config/ai.php' => config_path('ai.php'),
        ], ['ai', 'ai-config']);

        $this->publishes([
            __DIR__ . '/../stubs/agent.stub' => base_path('stubs/agent.stub'),
            __DIR__ . '/../stubs/structured-agent.stub' => base_path('stubs/structured-agent.stub'),
            __DIR__ . '/../stubs/tool.stub' => base_path('stubs/tool.stub'),
            __DIR__ . '/../stubs/agent-middleware.stub' => base_path('stubs/agent-middleware.stub'),
        ], 'ai-stubs');

        $this->publishesMigrations([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ]);
    }

    // On-demand providers belong to coroutine context and need no worker-global flush listeners.
}
