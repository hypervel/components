<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Hypervel\Ai\Ai;
use Hypervel\Ai\Audio;
use Hypervel\Ai\Classification;
use Hypervel\Ai\Classification\Boolean;
use Hypervel\Ai\Contracts\Providers\AudioProvider;
use Hypervel\Ai\Contracts\Providers\ClassificationProvider;
use Hypervel\Ai\Contracts\Providers\EmbeddingProvider;
use Hypervel\Ai\Contracts\Providers\ImageProvider;
use Hypervel\Ai\Contracts\Providers\Provider;
use Hypervel\Ai\Contracts\Providers\RerankingProvider;
use Hypervel\Ai\Contracts\Providers\TranscriptionProvider;
use Hypervel\Ai\Embeddings;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Events\ProviderFailedOver;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Ai\Files\Base64Audio;
use Hypervel\Ai\Image;
use Hypervel\Ai\PendingResponses\PendingAudioGeneration;
use Hypervel\Ai\PendingResponses\PendingClassification;
use Hypervel\Ai\PendingResponses\PendingEmbeddingsGeneration;
use Hypervel\Ai\PendingResponses\PendingImageGeneration;
use Hypervel\Ai\PendingResponses\PendingReranking;
use Hypervel\Ai\PendingResponses\PendingTranscriptionGeneration;
use Hypervel\Ai\Providers\Provider as BaseProvider;
use Hypervel\Ai\Reranking;
use Hypervel\Ai\Responses\AudioResponse;
use Hypervel\Ai\Responses\ClassificationResponse;
use Hypervel\Ai\Responses\EmbeddingsResponse;
use Hypervel\Ai\Responses\ImageResponse;
use Hypervel\Ai\Responses\RerankingResponse;
use Hypervel\Ai\Responses\TranscriptionResponse;
use Hypervel\Ai\Transcription;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Facades\Event;
use Hypervel\Tests\Ai\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\DataProvider;

class PendingProviderTest extends TestCase
{
    use VerifiesDoubles;

    /**
     * Configure contract-only providers for failover.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set([
            'ai.providers.first' => ['driver' => 'custom', 'key' => 'first'],
            'ai.providers.cohere' => ['driver' => 'custom'],
        ]);
    }

    #[DataProvider('generations')]
    public function testContractProviderFailoverRetainsTheOriginalNamedFallback(string $kind, string $contract, string $method, string $responseClass): void
    {
        $response = Double::for($responseClass)->strict();
        $exception = RateLimitedException::forProvider('first');
        $providers = [];

        foreach (['first', 'second'] as $name) {
            $provider = $name === 'first' ? Double::for($contract)->strict() : Double::for(PendingTestProvider::class)->strict();
            $provider->allows('name')->returns($name);
            $provider->expects('withHeaders')->with(['X-Provider' => $name]);

            if ($kind === 'embeddings') {
                $provider->allows('defaultEmbeddingsDimensions')->returns(1);
            }

            $expectation = $provider->expects($method)->with(Argument::all(
                fn (mixed ...$arguments): bool => in_array(['account' => $name], $arguments, true)
            ));

            if ($name === 'first') {
                $expectation->resolves(function () use ($exception): never {
                    Ai::build(['name' => 'second', 'driver' => 'custom', 'key' => 'replacement']);

                    throw $exception;
                });
            } else {
                $expectation->returns($response);
            }

            $providers[$name] = $provider;
        }

        $replacement = Double::for(PendingTestProvider::class)->strict();
        Ai::extend('custom', fn (Application $app, array $config): Provider => $config['key'] === 'replacement'
            ? $replacement
            : $providers[$config['name']]);
        Ai::build(['name' => 'second', 'driver' => 'custom', 'key' => 'original']);
        $events = [];
        Event::listen(ProviderFailedOver::class, function (ProviderFailedOver $event) use (&$events): void {
            $events[] = $event;
        });

        $pending = $this->pendingRequest($kind);
        $pending->withHeaders(fn (Provider $provider): array => ['X-Provider' => $provider->name()])
            ->withProviderOptions(fn (Provider $provider): array => ['account' => $provider->name()]);

        $names = ['first' => 'model', 'second' => 'model'];
        $actual = match ($kind) {
            'classification' => $pending->classify($names),
            'reranking' => $pending->rerank('greeting', $names),
            default => $pending->generate($names),
        };

        $this->assertSame($response, $actual);
        $this->assertCount(1, $events);
        $this->assertSame($providers['first'], $events[0]->provider);
        $this->assertSame($exception, $events[0]->exception);
        $replacement->unused();
    }

    #[DataProvider('generations')]
    public function testConfiguredDefaultsAcceptEnumsAndProviderLists(string $kind, string $contract, string $method, string $responseClass): void
    {
        $response = Double::for($responseClass)->strict();
        $provider = Double::for($contract)->strict();
        $provider->expects('withHeaders')->times(2)->with([]);
        $provider->expects($method)->times(2)->returns($response);

        if ($kind === 'embeddings') {
            $provider->allows('defaultEmbeddingsDimensions')->returns(1);
        }

        Ai::extend('custom', fn (): Provider => $provider);

        foreach ([Lab::Cohere, ['cohere' => 'model']] as $default) {
            config(['ai.default_for_' . ($kind === 'image' ? 'images' : $kind) => $default]);
            $pending = $this->pendingRequest($kind);
            $actual = match ($kind) {
                'classification' => $pending->classify(model: 'model'),
                'reranking' => $pending->rerank('greeting', model: 'model'),
                default => $pending->generate(model: 'model'),
            };

            $this->assertSame($response, $actual);
        }
    }

    /**
     * Provide each pending generation path.
     */
    public static function generations(): array
    {
        return [
            'audio' => ['audio', AudioProvider::class, 'audio', AudioResponse::class],
            'classification' => ['classification', ClassificationProvider::class, 'classify', ClassificationResponse::class],
            'embeddings' => ['embeddings', EmbeddingProvider::class, 'embeddings', EmbeddingsResponse::class],
            'image' => ['image', ImageProvider::class, 'image', ImageResponse::class],
            'reranking' => ['reranking', RerankingProvider::class, 'rerank', RerankingResponse::class],
            'transcription' => ['transcription', TranscriptionProvider::class, 'transcribe', TranscriptionResponse::class],
        ];
    }

    /**
     * Create the pending request for the generation path.
     */
    protected function pendingRequest(string $kind): PendingAudioGeneration|PendingClassification|PendingEmbeddingsGeneration|PendingImageGeneration|PendingReranking|PendingTranscriptionGeneration
    {
        return match ($kind) {
            'audio' => Audio::of('hello'),
            'classification' => Classification::of('hello')->question('valid', new Boolean('Is this a greeting?')),
            'embeddings' => Embeddings::for(['hello']),
            'image' => Image::of('a mountain'),
            'reranking' => Reranking::of(['hello']),
            'transcription' => Transcription::of(new Base64Audio(base64_encode('audio'), 'audio/wav')),
        };
    }
}

abstract class PendingTestProvider extends BaseProvider implements AudioProvider, ClassificationProvider, EmbeddingProvider, ImageProvider, RerankingProvider, TranscriptionProvider
{
}
