<?php

declare(strict_types=1);

namespace Hypervel\Ai\PendingResponses;

use Hypervel\Ai\Ai;
use Hypervel\Ai\Contracts\Question;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Events\ProviderFailedOver;
use Hypervel\Ai\Exceptions\FailoverableException;
use Hypervel\Ai\Files\File;
use Hypervel\Ai\PendingResponses\Concerns\ResolvesProviderOptions;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\ClassificationResponse;
use Hypervel\Http\UploadedFile;
use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Traits\Conditionable;
use InvalidArgumentException;

class PendingClassification
{
    use Conditionable;
    use ResolvesProviderOptions;

    /** @var array<string, Question> */
    protected array $questions = [];

    protected int $timeout = 30;

    /**
     * Create a new pending classification instance.
     *
     * @param array<string, mixed>|string $state
     * @param array<int, File|UploadedFile> $attachments
     *
     * @throws InvalidArgumentException if the state is blank
     */
    public function __construct(
        protected string|array $state,
        protected array $attachments = [],
    ) {
        if (blank($state)) {
            throw new InvalidArgumentException('A non-blank state is required to classify.');
        }
    }

    /**
     * Add a question to answer about the state.
     */
    public function question(string $key, Question $question): self
    {
        $this->questions[$key] = $question;

        return $this;
    }

    /**
     * Add questions to answer about the state.
     *
     * @param array<string, Question> $questions
     *
     * @throws InvalidArgumentException if any entry is not a Question keyed by a string
     */
    public function questions(array $questions): self
    {
        foreach ($questions as $key => $question) {
            if (! is_string($key) || ! $question instanceof Question) {
                throw new InvalidArgumentException('Questions must be Question instances keyed by a string.');
            }

            $this->question($key, $question);
        }

        return $this;
    }

    /**
     * Specify the timeout (in seconds) for the classification request.
     */
    public function timeout(int $seconds = 30): self
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * Answer the questions about the state.
     *
     * @throws InvalidArgumentException if no questions were added
     * @throws FailoverableException if every configured provider fails to classify the state
     */
    public function classify(Provider|Lab|array|string|null $provider = null, ?string $model = null): ClassificationResponse
    {
        if ($this->questions === []) {
            throw new InvalidArgumentException('At least one question is required to classify.');
        }

        $providers = Ai::resolveOnDemandProviders(Provider::providerAndModelPairs(
            $provider ?? Config::get('ai.default_for_classification'),
            $model
        ));

        $lastException = null;

        foreach ($providers as [$provider, $model]) {
            $provider = Ai::fakeableClassificationProvider($provider);

            [$providerOptions, $headers] = $this->resolveProviderOptionsAndHeaders($provider);

            $model ??= $provider->defaultClassificationModel();

            try {
                return $provider->withHeaders($headers)->classify($this->state, $this->questions, $model, $this->timeout, $providerOptions, $this->attachments);
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
