<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Ollama;

use Hypervel\Ai\Embeddings;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Ai\agent;

class BaseUrlTest extends TestCase
{
    protected string $customUrl = 'http://localhost:1234';

    public function testOllamaTextRequestsUseTheConfiguredBaseUrl(): void
    {
        $this->configureOllamaProvider($this->customUrl);

        Http::fake([
            '*' => Http::response([
                'model' => 'llama3.1:8b',
                'message' => ['role' => 'assistant', 'content' => 'Hello from local model'],
                'done_reason' => 'stop',
                'done' => true,
                'prompt_eval_count' => 1,
                'eval_count' => 1,
            ]),
        ]);

        $response = agent()->prompt('Hello', provider: 'ollama');

        $this->assertSame('Hello from local model', $response->text);

        Http::assertSentCount(1);
        $this->assertRequestSent('POST', "{$this->customUrl}/api/chat");
    }

    public function testOllamaRequestsFallBackToTheDefaultBaseUrl(): void
    {
        $this->configureOllamaProvider();

        Http::fake([
            '*' => Http::response([
                'model' => 'llama3.1:8b',
                'message' => ['role' => 'assistant', 'content' => 'Hello from Ollama'],
                'done_reason' => 'stop',
                'done' => true,
                'prompt_eval_count' => 1,
                'eval_count' => 1,
            ]),
        ]);

        $response = agent()->prompt('Hello', provider: 'ollama');

        $this->assertSame('Hello from Ollama', $response->text);

        Http::assertSentCount(1);
        $this->assertRequestSent('POST', 'http://localhost:11434/api/chat');
    }

    public function testOllamaEmbeddingsUseTheConfiguredBaseUrl(): void
    {
        $this->configureOllamaProvider($this->customUrl);

        Http::fake([
            '*' => Http::response([
                'model' => 'nomic-embed-text',
                'embeddings' => [[0.1, 0.2, 0.3]],
                'prompt_eval_count' => 5,
            ]),
        ]);

        Embeddings::for(['Hello world'])->generate(provider: 'ollama', model: 'nomic-embed-text');

        $this->assertRequestSent('POST', "{$this->customUrl}/api/embed");
    }

    /**
     * Configure the Ollama provider URL.
     */
    protected function configureOllamaProvider(?string $url = null): void
    {
        $config = [
            ...config('ai.providers.ollama'),
            'key' => '',
        ];

        if ($url !== null) {
            $config['url'] = $url;
        } else {
            unset($config['url']);
        }

        config(['ai.providers.ollama' => $config]);
    }

    /**
     * Assert a request was sent to the expected URL.
     */
    protected function assertRequestSent(string $method, string $url): void
    {
        Http::assertSent(fn (Request $request): bool => $request->method() === $method
            && $request->url() === $url);
    }
}
