<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Closure;
use Generator;
use Hypervel\Ai\Ai;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\Gateway\Gateway;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Events\AgentFailedOver;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Ai\Promptable;
use Hypervel\Ai\Prompts\AgentPrompt;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\AgentResponse;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\StreamableAgentResponse;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;

use function Hypervel\Coroutine\parallel;

class AgentProviderSelectionTest extends TestCase
{
    /**
     * Configure a provider whose requests are handled by the test.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai', [
            'default' => 'configured',
            'providers' => ['configured' => ['driver' => 'test', 'key' => 'configured-key']],
        ]);
    }

    /**
     * Register the test provider driver.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $factory = $this->provider(...);
        Ai::extend('test', static fn (Application $app, array $config): Provider => $factory($config));
    }

    public function testDistinctProvidersWithTheSameNameRemainSeparateFailoverAttempts(): void
    {
        $first = Ai::build(['driver' => 'test', 'name' => 'account', 'key' => 'first-key']);
        $second = Ai::build(['driver' => 'test', 'name' => 'account', 'key' => 'second-key']);
        $first->shouldReceive('prompt')->once()->andThrow(RateLimitedException::forProvider('account'));
        $second->shouldReceive('prompt')->once()->andReturnUsing(fn (AgentPrompt $prompt): AgentResponse => new AgentResponse(
            $prompt->invocationId,
            $prompt->provider->providerCredentials()['key'],
            new TextUsage,
            new Meta,
        ));

        $response = (new ProviderSelectionAgent)->prompt('Hello', provider: [$first, $second]);

        $this->assertSame('second-key', $response->text);
    }

    public function testNamedFallbackIsCapturedBeforeAnEarlierAttemptRebuildsIt(): void
    {
        $first = Ai::build(['driver' => 'test', 'name' => 'first', 'key' => 'first-key']);
        $fallback = Ai::build(['driver' => 'test', 'name' => 'account', 'key' => 'original-key']);
        $first->shouldReceive('prompt')->once()->andReturnUsing(function (): never {
            Ai::build(['driver' => 'test', 'name' => 'account', 'key' => 'replacement-key']);

            throw RateLimitedException::forProvider('first');
        });
        $fallback->shouldReceive('prompt')->once()->andReturnUsing(fn (AgentPrompt $prompt): AgentResponse => new AgentResponse(
            $prompt->invocationId,
            $prompt->provider->providerCredentials()['key'],
            new TextUsage,
            new Meta,
        ));

        $response = (new ProviderSelectionAgent)->prompt('Hello', provider: [$first, 'account']);

        $this->assertSame('original-key', $response->text);
    }

    public function testProviderObjectsWorkInAChildWithoutTheirNameRegistry(): void
    {
        $provider = Ai::build(['driver' => 'test', 'name' => 'account', 'key' => 'account-key']);
        $provider->shouldReceive('prompt')->once()->andReturnUsing(fn (AgentPrompt $prompt): AgentResponse => new AgentResponse(
            $prompt->invocationId,
            $prompt->provider->providerCredentials()['key'],
            new TextUsage,
            new Meta,
        ));

        [$response] = parallel([
            fn (): AgentResponse => (new ProviderSelectionAgent)->prompt('Hello', provider: $provider),
        ]);

        $this->assertSame('account-key', $response->text);
    }

    #[DataProvider('streamProviders')]
    public function testLazyStreamsCaptureNamedProvidersAndRunInTheInitiatingContext(array $providers, array $expectedResolvedAccounts): void
    {
        Ai::captureContextUsing(static function (): Closure {
            $account = CoroutineContext::get('ai-test.account');

            return static function (Closure $callback) use ($account): mixed {
                $previous = CoroutineContext::get('ai-test.account');
                CoroutineContext::set('ai-test.account', $account);

                try {
                    return $callback();
                } finally {
                    CoroutineContext::set('ai-test.account', $previous);
                }
            };
        });
        $resolvedAccounts = [];
        Ai::resolveProviderConfigUsing(static function (string $name, array $config) use (&$resolvedAccounts): array {
            $resolvedAccounts[] = CoroutineContext::get('ai-test.account');

            return $config;
        });
        $factory = $this->provider(...);
        Ai::extend('test', static function (Application $app, array $config) use ($factory): Provider {
            $provider = $factory($config);

            if ($config['name'] === 'configured') {
                $provider->shouldReceive('stream')->once()->andThrow(RateLimitedException::forProvider('configured'));
            }

            return $provider;
        });

        CoroutineContext::set('ai-test.account', 'producer');
        $fallback = Ai::build(['driver' => 'test', 'name' => 'account', 'key' => 'original-key']);
        $fallback->shouldReceive('stream')->once()->andReturnUsing(function (AgentPrompt $prompt): StreamableAgentResponse {
            $this->assertNotNull($prompt->contextRunner());

            return new StreamableAgentResponse($prompt->invocationId, function () use ($prompt): Generator {
                $this->assertSame('producer', CoroutineContext::get('ai-test.account'));

                yield new TextDelta('event', 'message', $prompt->provider->providerCredentials()['key'], 1);
            });
        });
        $stream = (new ProviderSelectionAgent)->stream('Hello', provider: $providers);
        $this->assertSame([], $resolvedAccounts);
        Ai::build(['driver' => 'test', 'name' => 'account', 'key' => 'replacement-key']);

        [$text] = parallel([function () use ($stream): string {
            CoroutineContext::set('ai-test.account', 'consumer');

            foreach ($stream as $event) {
                $this->assertSame('consumer', CoroutineContext::get('ai-test.account'));
            }

            $this->assertSame('consumer', CoroutineContext::get('ai-test.account'));

            return $stream->text;
        }]);

        $this->assertSame('original-key', $text);
        $this->assertSame($expectedResolvedAccounts, $resolvedAccounts);
    }

    /**
     * Provide the direct and failover stream construction paths.
     */
    public static function streamProviders(): array
    {
        return [
            'single provider' => [['account'], []],
            'failover' => [['configured', 'account'], ['producer']],
        ];
    }

    public function testProtectedPairOverrideRetainsRepeatedAttempts(): void
    {
        $provider = Ai::textProvider('configured');
        $provider->shouldReceive('prompt')->once()->ordered()->andThrow(RateLimitedException::forProvider('configured'));
        $provider->shouldReceive('prompt')->once()->ordered()->andReturnUsing(fn (AgentPrompt $prompt): AgentResponse => new AgentResponse(
            $prompt->invocationId,
            $prompt->model,
            new TextUsage,
            new Meta,
        ));
        $agent = new class extends ProviderSelectionAgent {
            /**
             * Return explicit attempts from the protected extension point.
             */
            protected function getProvidersAndModels(Provider|Lab|array|string|null $provider, ?string $model): array
            {
                return [['configured', 'first-model'], ['configured', 'second-model']];
            }
        };

        $this->assertSame('second-model', $agent->prompt('Hello')->text);
    }

    #[DataProvider('generationMethods')]
    public function testContractOnlyProvidersCanFailOverAndDispatchTheirIdentity(string $method): void
    {
        $primary = m::mock(TextProvider::class);
        $primary->shouldReceive($method)->once()->andThrow(RateLimitedException::forProvider('contract'));
        Ai::extend('contract', static fn (): TextProvider => $primary);

        $fallback = Ai::textProvider('configured');
        $fallback->shouldReceive($method)->once()->andReturnUsing(static function (AgentPrompt $prompt) use ($method): AgentResponse|StreamableAgentResponse {
            return $method === 'prompt'
                ? new AgentResponse($prompt->invocationId, 'fallback', new TextUsage, new Meta)
                : new StreamableAgentResponse($prompt->invocationId, static function (): Generator {
                    yield new TextDelta('event', 'message', 'fallback', 1);
                });
        });
        $failedOver = null;
        $this->app->make(Dispatcher::class)->listen(AgentFailedOver::class, static function (AgentFailedOver $event) use (&$failedOver): void {
            $failedOver = $event;
        });

        $response = (new ProviderSelectionAgent)->{$method}('Hello', provider: [
            'contract' => 'primary-model',
            'configured' => 'fallback-model',
        ]);

        if ($response instanceof StreamableAgentResponse) {
            iterator_to_array($response);
        }

        $this->assertSame('fallback', $response->text);
        $this->assertSame($primary, $failedOver->provider);
    }

    /**
     * Provide synchronous and streamed generation entry points.
     */
    public static function generationMethods(): array
    {
        return [['prompt'], ['stream']];
    }

    /**
     * Create a provider with real identity and mocked generation methods.
     */
    protected function provider(array $config): Provider&MockInterface
    {
        $provider = m::mock(Provider::class, TextProvider::class, [
            m::mock(Gateway::class), $config, $this->app->make(Dispatcher::class),
        ])->makePartial();
        $provider->shouldReceive('defaultTextModel')->andReturn('test-model');

        return $provider;
    }
}

class ProviderSelectionAgent implements Agent
{
    use Promptable;

    /**
     * Get the agent instructions.
     */
    public function instructions(): string
    {
        return 'Use the selected provider.';
    }
}
