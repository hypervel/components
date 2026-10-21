<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenRouter;

use Hypervel\Ai\Files\Document;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Ai\agent;
use function Hypervel\Tests\Ai\Fixtures\fakeOpenRouterResponse;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class BaseUrlTest extends TestCase
{
    protected string $customUrl = 'http://localhost:1234/v1';

    /**
     * Configure the OpenRouter credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.openrouter.key', 'test-key');
    }

    public function testOpenrouterTextRequestsUseTheConfiguredBaseUrl(): void
    {
        config(['ai.providers.openrouter.url' => $this->customUrl]);

        Http::fake(['*' => fakeOpenRouterResponse('Hello from local model')]);

        $response = agent()->prompt('Hello', provider: 'openrouter');

        $this->assertSame('Hello from local model', $response->text);

        Http::assertSentCount(1);
        $this->openRouterAssertRequestSent('POST', "{$this->customUrl}/chat/completions");
    }

    public function testOpenrouterRequestsFallBackToTheDefaultBaseUrl(): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('Hello from OpenRouter')]);

        $response = agent()->prompt('Hello', provider: 'openrouter');

        $this->assertSame('Hello from OpenRouter', $response->text);

        Http::assertSentCount(1);
        $this->openRouterAssertRequestSent('POST', 'https://openrouter.ai/api/v1/chat/completions');
    }

    public function testOpenrouterFileRequestsIgnoreAnInRegionBaseUrl(): void
    {
        config(['ai.providers.openrouter.url' => 'https://eu.openrouter.ai/api/v1']);

        Http::fake(['*' => Http::response(['id' => 'or_file_uploaded123'])]);

        Document::fromString('Hello, World!', 'text/plain')->as('hello.txt')->put(provider: 'openrouter');

        Http::assertSentCount(1);
        $this->openRouterAssertRequestSent('POST', 'https://openrouter.ai/api/v1/files');
    }

    /**
     * Assert the requested method and URL.
     */
    protected function openRouterAssertRequestSent(string $method, string $url): void
    {
        Http::assertSent(fn (Request $request): bool => $request->method() === $method
            && $request->url() === $url);
    }
}
