<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Responses\Data;

use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\ProviderToolCall;
use Hypervel\Ai\Responses\Data\Step;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Tests\TestCase;

class StepTest extends TestCase
{
    public function testStepToArrayReturnsAllPropertiesIncludingSerializedUsageAndMeta(): void
    {
        $usage = new TextUsage(10, 5);
        $meta = new Meta('openai', 'gpt-4o');
        $call = new ProviderToolCall('ws-1', 'web_search_call', ['query' => 'hypervel']);
        $step = new Step('test', [], [], FinishReason::Stop, $usage, $meta, 'Thinking.', [['type' => 'thinking', 'signature' => 'sig-1']], [$call]);

        $array = $step->toArray();

        $this->assertSame('test', $array['text']);
        $this->assertSame([], $array['tool_calls']);
        $this->assertSame([], $array['tool_results']);
        $this->assertSame('stop', $array['finish_reason']);
        $this->assertSame($usage, $array['usage']);
        $this->assertSame($meta, $array['meta']);
        $this->assertSame('Thinking.', $array['reasoning']);
        $this->assertSame([['type' => 'thinking', 'signature' => 'sig-1']], $array['replay_blocks']);
        $this->assertSame([$call], $array['provider_tool_calls']);
    }

    public function testStepJsonSerializeReturnsToArray(): void
    {
        $usage = new TextUsage(0, 0);
        $meta = new Meta;
        $step = new Step('', [], [], FinishReason::Unknown, $usage, $meta, '', []);

        $this->assertSame($step->toArray(), $step->jsonSerialize());
    }
}
