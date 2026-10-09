<?php

declare(strict_types=1);

namespace Hypervel\Ai\PendingResponses;

use Hypervel\Ai\Ai;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Events\ProviderFailedOver;
use Hypervel\Ai\Exceptions\FailoverableException;
use Hypervel\Ai\PendingResponses\Concerns\ResolvesProviderOptions;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\RerankingResponse;
use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Traits\Conditionable;
use InvalidArgumentException;

class PendingReranking
{
    use Conditionable;
    use ResolvesProviderOptions;

    protected ?int $limit = null;

    protected int $timeout = 30;

    /**
     * Create a new pending reranking instance.
     *
     * @param array<int, string> $documents
     *
     * @throws InvalidArgumentException if the documents are not a list, are empty, or contain non-string or blank entries
     */
    public function __construct(
        protected array $documents,
    ) {
        if (! array_is_list($documents)) {
            throw new InvalidArgumentException('Documents to rerank must be a list, not an associative array.');
        }

        if (blank($documents)) {
            throw new InvalidArgumentException('At least one document is required to rerank.');
        }

        foreach ($documents as $index => $document) {
            if (! is_string($document) || blank($document)) {
                throw new InvalidArgumentException("Each document to rerank must be a non-blank string (index {$index}).");
            }
        }
    }

    /**
     * Limit the number of results to return.
     */
    public function limit(?int $limit): self
    {
        $this->limit = $limit;

        return $this;
    }

    /**
     * Specify the timeout (in seconds) for the reranking request.
     */
    public function timeout(int $seconds = 30): self
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * Rerank the documents based on their relevance to the query.
     *
     * @throws FailoverableException if every configured provider fails to rerank the documents
     */
    public function rerank(string $query, Provider|Lab|array|string|null $provider = null, ?string $model = null): RerankingResponse
    {
        $providers = Ai::resolveOnDemandProviders(Provider::providerAndModelPairs(
            $provider ?? Config::get('ai.default_for_reranking'),
            $model
        ));

        $lastException = null;

        foreach ($providers as [$provider, $model]) {
            $provider = Ai::fakeableRerankingProvider($provider);

            [$providerOptions, $headers] = $this->resolveProviderOptionsAndHeaders($provider);

            $model ??= $provider->defaultRerankingModel();

            try {
                return $provider->withHeaders($headers)->rerank($this->documents, $query, $this->limit, $model, $this->timeout, $providerOptions);
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
}
