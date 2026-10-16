<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Anthropic;

use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\AnthropicHelpers;
use Hypervel\Tests\Ai\TestCase;

class BaseUrlTest extends TestCase
{
    use AnthropicHelpers;

    public function testAnthropicRequestsUseTheConfiguredBaseUrl(): void
    {
        config(['ai.providers.anthropic.url' => 'https://custom-proxy.example.com/v1']);

        Http::fake([
            'custom-proxy.example.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );

        Http::assertSent(fn ($request): bool => $request->url() === 'https://custom-proxy.example.com/v1/messages');
    }

    public function testAnthropicRequestsFallBackToTheDefaultBaseUrl(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.anthropic.com/v1/messages');
    }
}
