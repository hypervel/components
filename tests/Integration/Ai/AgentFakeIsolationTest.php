<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Ai;

use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\TestCase;
use Hypervel\Tests\Integration\Ai\Fixtures\Agents\SecondaryAssistantAgent;

use function Hypervel\Tests\Ai\Fixtures\requiresApiKey;

require_once __DIR__ . '/../../Ai/Fixtures/helpers.php';

class AgentFakeIsolationTest extends TestCase
{
    public function testFakingOneAgentDoesNotAffectAnotherAgent(): void
    {
        requiresApiKey('GROQ_API_KEY');

        AssistantAgent::fake(fn (): string => 'Fake response');

        $fakeResponse = (new AssistantAgent)->prompt(
            'What is the name of the PHP framework created by Taylor Otwell?',
            provider: 'groq',
            model: 'openai/gpt-oss-20b',
        );

        $realResponse = (new SecondaryAssistantAgent)->prompt(
            'What is the name of the PHP framework created by Taylor Otwell?',
            provider: 'groq',
            model: 'openai/gpt-oss-20b',
        );

        $this->assertSame('Fake response', $fakeResponse->text);
        $this->assertStringContainsString('Laravel', $realResponse->text);
    }
}
