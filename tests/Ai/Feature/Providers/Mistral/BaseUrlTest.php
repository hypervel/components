<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Mistral;

use Hypervel\Ai\Audio;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\MistralHelpers;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Ai\agent;

class BaseUrlTest extends TestCase
{
    use MistralHelpers;

    protected string $customUrl = 'http://localhost:1234/v1';

    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.mistral.key', 'test-key');
    }

    public function testMistralRequestsUseTheConfiguredBaseUrl(): void
    {
        $this->configureMistralProvider($this->customUrl);

        Http::fake([
            '*' => $this->fakeTextResponse('Hello from custom'),
        ]);

        $response = agent()->prompt('Hello', provider: 'mistral');

        $this->assertSame('Hello from custom', $response->text);

        Http::assertSentCount(1);
        $this->assertRequestSent('POST', "{$this->customUrl}/chat/completions");
    }

    public function testMistralRequestsFallBackToTheDefaultBaseUrl(): void
    {
        Http::fake([
            '*' => $this->fakeTextResponse('Hello from Mistral'),
        ]);

        $response = agent()->prompt('Hello', provider: 'mistral');

        $this->assertSame('Hello from Mistral', $response->text);

        Http::assertSentCount(1);
        $this->assertRequestSent('POST', 'https://api.mistral.ai/v1/chat/completions');
    }

    public function testMistralAudioRequestsUseTheConfiguredBaseUrl(): void
    {
        $this->configureMistralProvider($this->customUrl);

        Http::fake([
            '*' => Http::response(['audio_data' => base64_encode('fake-audio-bytes')]),
        ]);

        Audio::of('Hello')->generate(provider: 'mistral');

        Http::assertSentCount(1);
        $this->assertRequestSent('POST', "{$this->customUrl}/audio/speech");
    }

    /**
     * Configure the provider with an optional custom URL.
     */
    protected function configureMistralProvider(?string $url = null): void
    {
        config(['ai.providers.mistral' => array_filter([
            ...config('ai.providers.mistral'),
            'key' => 'test-key',
            'url' => $url,
        ])]);
    }

    /**
     * Assert the request method and URL.
     */
    protected function assertRequestSent(string $method, string $url): void
    {
        Http::assertSent(fn (Request $request): bool => $request->method() === $method
            && $request->url() === $url);
    }
}
