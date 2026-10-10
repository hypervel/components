<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Hypervel\Ai\Ai;
use Hypervel\Ai\AiManager;
use Hypervel\Ai\Contracts\Providers\AudioProvider;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Gateway\OpenAi\OpenAiGateway;
use Hypervel\Ai\Providers\OpenAiProvider;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Facades\Facade;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;
use LogicException;
use Mockery as m;

class AiManagerTest extends TestCase
{
    protected AiManager $manager;

    /**
     * Configure the custom driver used by the contract tests.
     */
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set([
            'ai.default' => 'configured',
            'ai.providers.configured' => ['driver' => 'custom', 'key' => 'configured-key'],
        ]);
    }

    /**
     * Resolve the manager.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = $this->app->make(AiManager::class);
    }

    public function testCanGetAnOpenaiProviderInstance(): void
    {
        $this->assertInstanceOf(OpenAiProvider::class, Ai::textProvider('openai'));
    }

    public function testProviderTypeIsEnsured(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('does not support audio generation');

        Ai::audioProvider('anthropic');
    }

    public function testAConfiguredProviderCastsToItsDriver(): void
    {
        config(['ai.providers.cloudflare' => ['driver' => 'openai-compatible', 'url' => 'https://example.com/v1']]);

        $this->assertSame('openai-compatible', (string) Ai::textProvider('cloudflare'));
    }

    public function testAnOnDemandProviderCannotReplaceAConfiguredProvider(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The provider name [anthropic] is already taken.');

        Ai::build(['name' => 'anthropic', 'driver' => 'anthropic', 'key' => 'tenant-key']);
    }

    public function testAnOnDemandProviderCannotTakeABuiltInProviderName(): void
    {
        config(['ai.providers' => []]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The provider name [openai] is already taken.');

        Ai::build(['name' => 'openai', 'driver' => 'anthropic', 'key' => 'tenant-key']);
    }

    public function testRebuildingANamedOnDemandProviderUsesTheNewConfiguration(): void
    {
        Ai::build(['name' => 'tenant', 'driver' => 'anthropic', 'key' => 'old-key']);

        $this->assertSame(['key' => 'new-key'], Ai::build(['name' => 'tenant', 'driver' => 'anthropic', 'key' => 'new-key'])->providerCredentials());
    }

    public function testAnOnDemandProviderKeepsItsInternalFlagOutOfItsAdditionalConfiguration(): void
    {
        $this->assertSame(['url' => 'https://tenant.example.com/v1'], Ai::build(['driver' => 'anthropic', 'key' => 'tenant-key', 'url' => 'https://tenant.example.com/v1'])->additionalConfiguration());
    }

    public function testFlushingStateForgetsOnDemandProvidersButKeepsConfiguredProviders(): void
    {
        $configured = Ai::textProvider('anthropic');
        $onDemand = Ai::build(['driver' => 'anthropic', 'key' => 'tenant-key']);

        Ai::flushState();

        $this->assertSame($configured, Ai::textProvider('anthropic'));
        $this->assertThrows(fn (): TextProvider => Ai::textProvider($onDemand->name()), InvalidArgumentException::class, 'was not built in this operation');
    }

    // REMOVED: queue-loop and Octane flush tests; on-demand providers belong to their coroutine.

    public function testDriverExtensionsSurviveBetweenQueueJobs(): void
    {
        config(['ai.providers.custom' => ['driver' => 'custom']]);

        Ai::extend('custom', fn (Application $app, array $config): OpenAiProvider => new OpenAiProvider(
            $app->make(OpenAiGateway::class),
            $config,
            $app->make(Dispatcher::class),
        ));

        $runJobOnFreshScope = function (): TextProvider {
            $this->app->forgetScopedInstances();
            Facade::clearResolvedInstances();

            return Ai::textProvider('custom');
        };

        $this->assertInstanceOf(OpenAiProvider::class, $runJobOnFreshScope());
        $this->assertInstanceOf(OpenAiProvider::class, $runJobOnFreshScope());
    }

    public function testCustomDriversMayImplementProviderContractsWithoutExtendingTheBaseClass(): void
    {
        $provider = m::mock(AudioProvider::class);
        $this->manager->extend('custom', static fn (): AudioProvider => $provider);

        $this->assertSame($provider, $this->manager->audioProvider());
    }

    public function testConversationPartitionRegistrationCannotBeReplaced(): void
    {
        $this->manager->resolveConversationPartitionUsing('account_id', static fn (): string => 'first');

        $this->expectException(LogicException::class);
        $this->manager->resolveConversationPartitionUsing('other_id', static fn (): string => 'second');
    }

    public function testConfiguredConversationPartitionFailsClosedWhenItsContextIsMissing(): void
    {
        $this->manager->resolveConversationPartitionUsing('account_id', static fn (): int|string|null => CoroutineContext::get('ai-test.account'));
        CoroutineContext::set('ai-test.account', 42);

        $this->assertSame('account_id', $this->manager->conversationPartitionColumn());
        $this->assertSame(42, $this->manager->conversationPartition());
        CoroutineContext::set('ai-test.account', 'account-one');
        $this->assertSame('account-one', $this->manager->conversationPartition());

        CoroutineContext::forget('ai-test.account');
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('could not be resolved');
        $this->manager->conversationPartition();
    }

    public function testEmbeddingsCacheScopeAllowsCentralUseButRejectsAnEmptyScope(): void
    {
        $this->manager->resolveEmbeddingsCacheScopeUsing(static fn (): ?string => CoroutineContext::get('ai-test.cache-scope'));

        $this->assertNull($this->manager->embeddingsCacheScope());
        CoroutineContext::set('ai-test.cache-scope', 'account-one');
        $this->assertSame('account-one', $this->manager->embeddingsCacheScope());

        CoroutineContext::set('ai-test.cache-scope', '');
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must not be empty');
        $this->manager->embeddingsCacheScope();
    }
}
