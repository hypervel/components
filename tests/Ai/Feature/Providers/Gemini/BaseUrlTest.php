<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Gemini;

use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\GeminiHelpers;
use Hypervel\Tests\Ai\TestCase;

class BaseUrlTest extends TestCase
{
    use GeminiHelpers;

    public function testGeminiRequestsUseTheConfiguredBaseUrl(): void
    {
        config(['ai.providers.gemini.url' => 'https://custom-proxy.example.com/v1']);

        Http::fake([
            'custom-proxy.example.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'gemini',
        );

        Http::assertSent(fn (Request $request): bool => str_contains((string) $request->url(), 'https://custom-proxy.example.com/v1'));
    }

    public function testGeminiRequestsFallBackToTheDefaultBaseUrl(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'gemini',
        );

        Http::assertSent(fn (Request $request): bool => str_contains((string) $request->url(), 'generativelanguage.googleapis.com'));
    }
}
