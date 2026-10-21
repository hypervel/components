<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\DeepSeek;

use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Ai\agent;
use function Hypervel\Tests\Ai\Fixtures\fakeDeepSeekResponse;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class BaseUrlTest extends TestCase
{
    protected string $customUrl = 'http://localhost:1234/v1';

    public function testDeepSeekTextRequestsUseTheConfiguredBaseUrl(): void
    {
        $this->configureDeepSeekProvider($this->customUrl);

        Http::fake([
            '*' => fakeDeepSeekResponse('Hello from local model'),
        ]);

        $response = agent()->prompt('Hello', provider: 'deepseek');

        $this->assertSame('Hello from local model', $response->text);

        Http::assertSentCount(1);
        $this->deepseekAssertRequestSent('POST', "{$this->customUrl}/chat/completions");
    }

    public function testDeepSeekRequestsFallBackToTheDefaultBaseUrl(): void
    {
        $this->configureDeepSeekProvider();

        Http::fake([
            '*' => fakeDeepSeekResponse('Hello from DeepSeek'),
        ]);

        $response = agent()->prompt('Hello', provider: 'deepseek');

        $this->assertSame('Hello from DeepSeek', $response->text);

        Http::assertSentCount(1);
        $this->deepseekAssertRequestSent('POST', 'https://api.deepseek.com/v1/chat/completions');
    }

    /**
     * Configure the provider's credentials and optional base URL.
     */
    protected function configureDeepSeekProvider(?string $url = null): void
    {
        config(['ai.providers.deepseek' => array_filter([
            ...config('ai.providers.deepseek'),
            'key' => 'test-key',
            'url' => $url,
        ])]);
    }

    /**
     * Assert that a request was sent to the provider endpoint.
     */
    protected function deepseekAssertRequestSent(string $method, string $url): void
    {
        Http::assertSent(fn (Request $request): bool => $request->method() === $method
            && $request->url() === $url);
    }
}
