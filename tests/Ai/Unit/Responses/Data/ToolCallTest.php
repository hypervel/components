<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Responses\Data;

use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Tests\TestCase;

class ToolCallTest extends TestCase
{
    public function testToolCallStoresAllProperties(): void
    {
        $toolCall = new ToolCall(
            id: 'call_123',
            name: 'get_weather',
            arguments: ['city' => 'San Francisco'],
            resultId: 'res_456',
            reasoningId: 'reason_789',
            reasoningSummary: ['thought' => 'I should check the weather'],
            reasoningEncryptedContent: 'enc-blob-1',
            thoughtSignature: 'sig-1',
        );

        $this->assertSame('call_123', $toolCall->id);
        $this->assertSame('get_weather', $toolCall->name);
        $this->assertSame(['city' => 'San Francisco'], $toolCall->arguments);
        $this->assertSame('res_456', $toolCall->resultId);
        $this->assertSame('reason_789', $toolCall->reasoningId);
        $this->assertSame(['thought' => 'I should check the weather'], $toolCall->reasoningSummary);
        $this->assertSame('enc-blob-1', $toolCall->reasoningEncryptedContent);
        $this->assertSame('sig-1', $toolCall->thoughtSignature);
    }

    public function testToolCallToArrayReturnsAllProperties(): void
    {
        $toolCall = new ToolCall('id', 'name', ['arg' => 'val']);

        $array = $toolCall->toArray();

        $this->assertSame([
            'id' => 'id',
            'name' => 'name',
            'arguments' => ['arg' => 'val'],
            'result_id' => null,
            'reasoning_id' => null,
            'reasoning_summary' => null,
            'reasoning_encrypted_content' => null,
            'thought_signature' => null,
        ], $array);
    }

    public function testToolCallJsonSerializeReturnsToArray(): void
    {
        $toolCall = new ToolCall('id', 'name', []);

        $this->assertSame($toolCall->toArray(), $toolCall->jsonSerialize());
    }
}
