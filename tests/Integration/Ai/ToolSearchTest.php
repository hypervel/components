<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Ai;

use Hypervel\Tests\Ai\TestCase;
use Hypervel\Tests\Integration\Ai\Fixtures\Agents\ToolSearchAgent;
use Hypervel\Tests\Integration\Ai\Fixtures\Providers;
use PHPUnit\Framework\Attributes\DataProviderExternal;

use function Hypervel\Tests\Ai\Fixtures\requiresApiKey;

require_once __DIR__ . '/../../Ai/Fixtures/helpers.php';

class ToolSearchTest extends TestCase
{
    #[DataProviderExternal(Providers::class, 'toolSearch')]
    public function testAgentsDiscoverAndCallADeferredToolThroughHostedToolSearch(string $provider, string $apiKey, string $model): void
    {
        requiresApiKey($apiKey);

        $response = (new ToolSearchAgent)->prompt(
            'What is the secret authorization code for this session?',
            provider: $provider,
            model: $model,
        );

        $this->assertStringContainsString('ZEBRA-4417', $response->text);
        $this->assertContains('SecretCodeGenerator', $response->toolCalls->pluck('name'));
    }
}
