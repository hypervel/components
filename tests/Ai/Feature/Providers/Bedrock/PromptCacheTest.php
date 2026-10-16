<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Bedrock;

use Hypervel\Ai\Attributes\CacheInstructions;
use Hypervel\Ai\Attributes\CacheToolDefinitions;
use Hypervel\Ai\Gateway\TextGenerationOptions;
use Hypervel\Tests\Ai\Fixtures\Agents\PromptCacheAgent;
use Hypervel\Tests\Ai\Fixtures\BedrockHelpers;
use Hypervel\Tests\Ai\Fixtures\Tools\FixedNumberGenerator;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

class PromptCacheTest extends TestCase
{
    use BedrockHelpers;

    public function testCacheInstructionsAttributeAppendsACachePoint(): void
    {
        $parameters = $this->capturedConverseParameters(
            TextGenerationOptions::forAgent(new #[CacheInstructions] class extends PromptCacheAgent {})
        );

        $this->assertSame([
            ['text' => 'You are a helpful assistant.'],
            ['cachePoint' => ['type' => 'default']],
        ], $parameters['system']);
    }

    public function testCacheToolDefinitionsAttributeAppendsACachePointToToolConfig(): void
    {
        $parameters = $this->capturedConverseParameters(
            TextGenerationOptions::forAgent(new #[CacheToolDefinitions] class extends PromptCacheAgent {}),
            [new FixedNumberGenerator],
        );

        $this->assertSame([['text' => 'You are a helpful assistant.']], $parameters['system']);
        $this->assertSame(['cachePoint' => ['type' => 'default']], end($parameters['toolConfig']['tools']));
    }

    public function testAnAgentWithoutCacheAttributesAddsNoCachePoints(): void
    {
        $parameters = $this->capturedConverseParameters(
            TextGenerationOptions::forAgent(new PromptCacheAgent),
            [new FixedNumberGenerator],
        );

        $this->assertSame([['text' => 'You are a helpful assistant.']], $parameters['system']);
        foreach ($parameters['toolConfig']['tools'] as $tool) {
            $this->assertArrayHasKey('toolSpec', $tool);
        }
    }

    public function testRequestedTtlIsAddedToBedrockCachePoint(): void
    {
        $parameters = $this->capturedConverseParameters(
            TextGenerationOptions::forAgent(new #[CacheInstructions('1h')] class extends PromptCacheAgent {})
        );

        $this->assertSame([
            ['text' => 'You are a helpful assistant.'],
            ['cachePoint' => ['type' => 'default', 'ttl' => '1h']],
        ], $parameters['system']);
    }

    public function testLongerInstructionsTtlRequiresToolsCacheToUseSameTtl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->capturedConverseParameters(
            TextGenerationOptions::forAgent(new #[CacheInstructions('1h')] #[CacheToolDefinitions('5m')] class extends PromptCacheAgent {}),
            [new FixedNumberGenerator],
        );
    }

    #[DataProvider('absentCacheTargets')]
    public function testCacheOrderIgnoresTargetsAbsentFromTheRequest(bool $withInstructions, bool $withTools): void
    {
        $parameters = $this->capturedConverseParameters(
            TextGenerationOptions::forAgent(new #[CacheInstructions('1h')] #[CacheToolDefinitions] class extends PromptCacheAgent {}),
            $withTools ? [new FixedNumberGenerator] : [],
            $withInstructions ? 'Instructions' : null,
        );

        if ($withInstructions) {
            $this->assertSame(['cachePoint' => ['type' => 'default', 'ttl' => '1h']], end($parameters['system']));
        } else {
            $this->assertArrayNotHasKey('system', $parameters);
        }

        if ($withTools) {
            $this->assertSame(['cachePoint' => ['type' => 'default']], end($parameters['toolConfig']['tools']));
        } else {
            $this->assertArrayNotHasKey('toolConfig', $parameters);
        }
    }

    /**
     * Cover instruction and tool cache attributes without both request targets.
     */
    public static function absentCacheTargets(): array
    {
        return ['instructions only' => [true, false], 'tools only' => [false, true]];
    }

    public function testCachePointsSurviveProviderOptionsThatOverrideSameKeys(): void
    {
        $parameters = $this->capturedConverseParameters(
            TextGenerationOptions::forAgent(new #[CacheInstructions] class(options: ['system' => [['text' => 'Overridden instructions.']]]) extends PromptCacheAgent {}),
        );

        $this->assertSame([
            ['text' => 'Overridden instructions.'],
            ['cachePoint' => ['type' => 'default']],
        ], $parameters['system']);
    }

    public function testToolsTargetIsANoOpWithoutToolConfig(): void
    {
        $parameters = $this->capturedConverseParameters(
            TextGenerationOptions::forAgent(new #[CacheToolDefinitions] class(withTools: false) extends PromptCacheAgent {}),
        );

        $this->assertArrayNotHasKey('toolConfig', $parameters);
    }
}
