<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Ai;

use Hypervel\Ai\Providers\Tools\CodeExecution;
use Hypervel\Ai\Streaming\Events\ProviderToolEvent;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Tests\Ai\TestCase;
use Hypervel\Tests\Integration\Ai\Fixtures\Providers;
use PHPUnit\Framework\Attributes\DataProviderExternal;

use function Hypervel\Ai\agent;
use function Hypervel\Tests\Ai\Fixtures\requiresApiKey;

require_once __DIR__ . '/../../Ai/Fixtures/helpers.php';

class CodeExecutionTest extends TestCase
{
    #[DataProviderExternal(Providers::class, 'codeExecution')]
    public function testAgentsAnswerUsingTheHostedCodeExecutionTool(string $provider, string $apiKey, string $model): void
    {
        requiresApiKey($apiKey);

        $response = agent(
            'Use the code execution tool to compute the answer. Reply with the hex digest only.',
            tools: [new CodeExecution],
        )->prompt(
            'What is the SHA-256 hex digest of the exact ASCII string hypervel-ai-code-execution?',
            provider: $provider,
            model: $model,
        );

        $this->assertStringContainsString(hash('sha256', 'hypervel-ai-code-execution'), $response->text);
    }

    #[DataProviderExternal(Providers::class, 'codeExecution')]
    public function testAgentsStreamProviderToolEventsWhileUsingTheHostedCodeExecutionTool(string $provider, string $apiKey, string $model): void
    {
        requiresApiKey($apiKey);

        $response = agent(
            'Use the code execution tool to compute the answer. Reply with the hex digest only.',
            tools: [new CodeExecution],
        )->stream(
            'What is the SHA-256 hex digest of the exact ASCII string hypervel-ai-code-execution?',
            provider: $provider,
            model: $model,
        );

        $providerEvents = [];
        $text = '';

        foreach ($response as $event) {
            if ($event instanceof ProviderToolEvent) {
                $providerEvents[] = $event;
            } elseif ($event instanceof TextDelta) {
                $text .= $event->delta;
            }
        }

        $streamedCode = array_filter($providerEvents, fn (ProviderToolEvent $event): bool => str_contains(json_encode($event->data), 'sha256'));

        $this->assertNotEmpty($streamedCode);
        $this->assertSame($provider, $providerEvents[0]->provider);
        $this->assertStringContainsString(hash('sha256', 'hypervel-ai-code-execution'), $text);
    }
}
