<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Anthropic;

use Hypervel\Ai\Ai;
use Hypervel\Ai\AiManager;
use Hypervel\Ai\Jobs\InvokeAgent;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\OnDemandProviderAgent;
use Hypervel\Tests\Ai\Fixtures\AnthropicHelpers;
use Hypervel\Tests\Ai\TestCase;

class OnDemandProviderTest extends TestCase
{
    use AnthropicHelpers;

    /**
     * Configure encryption for queued providers.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    }

    public function testPromptsUseAnOnDemandProviderPassedOnItsOwn(): void
    {
        Http::fake(['tenant.example.com/*' => $this->fakeTextResponse()]);

        (new AssistantAgent)->prompt('Hi', provider: Ai::build([
            'driver' => 'anthropic',
            'key' => 'tenant-key',
            'url' => 'https://tenant.example.com/v1',
        ]), model: 'claude-opus-5-5');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://tenant.example.com/v1/messages'
            && $request->header('x-api-key') === ['tenant-key']
            && $request['model'] === 'claude-opus-5-5');
    }

    public function testPromptsFailOverBetweenOnDemandProviders(): void
    {
        Http::fake([
            'primary.example.com/*' => Http::response([], 429),
            'backup.example.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt('Hi', provider: [
            Ai::build(['driver' => 'anthropic', 'key' => 'primary-key', 'url' => 'https://primary.example.com/v1']),
            Ai::build(['driver' => 'anthropic', 'key' => 'backup-key', 'url' => 'https://backup.example.com/v1']),
        ]);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://primary.example.com/v1/messages'
            && $request->header('x-api-key') === ['primary-key']);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://backup.example.com/v1/messages'
            && $request->header('x-api-key') === ['backup-key']);
    }

    public function testAnAgentProviderMethodRebuildsItsOnDemandProviderOnTheQueueWorker(): void
    {
        Http::fake(['api.anthropic.com/*' => $this->fakeTextResponse()]);

        $job = unserialize(serialize(new InvokeAgent(new OnDemandProviderAgent('tenant-key'), 'Hi')));

        app()->forgetInstance(AiManager::class);
        Ai::clearResolvedInstances();

        $job->handle();

        Http::assertSent(fn ($request): bool => $request->header('x-api-key') === ['tenant-key']);
    }

    public function testAQueuedPromptRebuildsASingleOnDemandProviderBuiltAtTheCallSite(): void
    {
        Http::fake(['api.anthropic.com/*' => $this->fakeTextResponse()]);

        $job = unserialize(serialize(new InvokeAgent(new AssistantAgent, 'Hi', provider: Ai::build([
            'driver' => 'anthropic',
            'key' => 'tenant-key',
        ]))));

        app()->forgetInstance(AiManager::class);
        Ai::clearResolvedInstances();

        $job->handle();

        Http::assertSent(fn ($request): bool => $request->header('x-api-key') === ['tenant-key']);
    }

    public function testAQueuedPromptRebuildsAnOnDemandProviderBuiltAtTheCallSite(): void
    {
        Http::fake(['tenant.example.com/*' => $this->fakeTextResponse()]);

        $payload = serialize(new InvokeAgent(new AssistantAgent, 'Hi', provider: [Ai::build([
            'driver' => 'anthropic',
            'key' => 'tenant-key',
            'url' => 'https://tenant.example.com/v1',
        ])]));

        app()->forgetInstance(AiManager::class);
        Ai::clearResolvedInstances();

        unserialize($payload)->handle();

        Http::assertSent(fn ($request): bool => $request->url() === 'https://tenant.example.com/v1/messages'
            && $request->header('x-api-key') === ['tenant-key']);
    }

    public function testASerializedOnDemandProviderKeepsItsKeyOutOfThePayload(): void
    {
        $this->assertStringNotContainsString('tenant-key', serialize(Ai::build(['driver' => 'anthropic', 'key' => 'tenant-key'])));
    }

    public function testASerializedConfiguredProviderTravelsByName(): void
    {
        config(['ai.providers.anthropic' => ['driver' => 'anthropic', 'key' => 'configured-key']]);

        $provider = unserialize(serialize(Ai::textProvider('anthropic')));

        $this->assertStringNotContainsString('configured-key', serialize(Ai::textProvider('anthropic')));
        $this->assertSame('anthropic', $provider->name());
        $this->assertSame(['key' => 'configured-key'], $provider->providerCredentials());
    }
}
