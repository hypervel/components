<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Hypervel\Ai\AiManager;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\AgentResponse;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;

use function Hypervel\Ai\agent;

class AiManagerDefaultConfigTest extends TestCase
{
    public function testStringDefaultWorks(): void
    {
        config(['ai.default' => 'openai']);

        $this->assertSame('openai', app(AiManager::class)->getDefaultInstance());
    }

    public function testArrayDefaultViaAiFacadeThrowsHelpfulError(): void
    {
        config(['ai.default' => ['text' => 'anthropic']]);

        $this->assertThrows(fn (): string => app(AiManager::class)->getDefaultInstance(), InvalidArgumentException::class, 'must be a string provider name or a Lab enum, not an array');
    }

    public function testArrayDefaultViaAgentPromptThrowsHelpfulError(): void
    {
        config(['ai.default' => ['text' => 'anthropic']]);

        $this->assertThrows(fn (): AgentResponse => agent(instructions: 'test')->prompt('hello'), InvalidArgumentException::class, 'must be a string provider name or a Lab enum, not an array');
    }

    public function testFailoverProviderListStillWorks(): void
    {
        $this->assertSame(['anthropic' => 'claude-3-5-sonnet', 'openai' => 'gpt-4'], Provider::formatProviderAndModelList(['anthropic' => 'claude-3-5-sonnet', 'openai' => 'gpt-4']));
    }
}
