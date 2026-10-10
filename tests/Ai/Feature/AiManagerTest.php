<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Hypervel\Ai\AiManager;
use Hypervel\Ai\Contracts\Providers\AudioProvider;
use Hypervel\Config\Repository;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Tests\TestCase;
use LogicException;
use Mockery as m;

class AiManagerTest extends TestCase
{
    protected AiManager $manager;

    /**
     * Create the manager with a configured custom driver.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $application = m::mock(Application::class);
        $application->shouldReceive('make')->with('config')->andReturn(new Repository([
            'ai' => [
                'default' => 'configured',
                'providers' => ['configured' => ['driver' => 'custom', 'key' => 'configured-key']],
            ],
        ]));
        $this->manager = new AiManager($application);
    }

    public function testCustomDriversMayImplementProviderContractsWithoutExtendingTheBaseClass(): void
    {
        $provider = m::mock(AudioProvider::class);
        $this->manager->extend('custom', static fn (): AudioProvider => $provider);

        $this->assertSame($provider, $this->manager->audioProvider());
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('does not support image generation');

        $this->manager->imageProvider();
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
