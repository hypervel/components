<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Responses\Data;

use Hypervel\Ai\Responses\Data\ToolResult;
use Hypervel\Tests\TestCase;

class ToolResultTest extends TestCase
{
    public function testToolResultStoresAllProperties(): void
    {
        $result = new ToolResult(
            id: 'call_123',
            name: 'get_weather',
            arguments: ['city' => 'London'],
            result: ['temp' => 20],
            resultId: 'msg_789'
        );

        $this->assertSame('call_123', $result->id);
        $this->assertSame('get_weather', $result->name);
        $this->assertSame(['city' => 'London'], $result->arguments);
        $this->assertSame(['temp' => 20], $result->result);
        $this->assertSame('msg_789', $result->resultId);
    }

    public function testToolResultToArrayReturnsAllProperties(): void
    {
        $result = new ToolResult('id', 'name', [], 'raw result');

        $array = $result->toArray();

        $this->assertSame([
            'id' => 'id',
            'name' => 'name',
            'arguments' => [],
            'result' => 'raw result',
            'result_id' => null,
        ], $array);
    }

    public function testToolResultJsonSerializeReturnsToArray(): void
    {
        $result = new ToolResult('id', 'name', [], 'val');

        $this->assertSame($result->toArray(), $result->jsonSerialize());
    }

    public function testToolResultToArrayIncludesTheDeniedKeyOnlyWhenDenied(): void
    {
        $this->assertArrayNotHasKey('denied', (new ToolResult('id', 'name', [], 'val'))->toArray());
        $this->assertTrue((new ToolResult('id', 'name', [], 'val', denied: true))->toArray()['denied']);
    }

    public function testToolResultFromArrayHydratesTheDeniedFlagFromItsKey(): void
    {
        $this->assertFalse(ToolResult::fromArray(['id' => 'id', 'name' => 'name', 'arguments' => [], 'result' => 'val'])->denied);
        $this->assertTrue(ToolResult::fromArray(['id' => 'id', 'name' => 'name', 'arguments' => [], 'result' => 'val', 'denied' => true])->denied);
    }

    public function testToolResultToArrayIncludesTheFailedKeyOnlyWhenFailed(): void
    {
        $this->assertArrayNotHasKey('failed', (new ToolResult('id', 'name', [], 'val'))->toArray());
        $this->assertTrue((new ToolResult('id', 'name', [], 'Tool not found', failed: true))->toArray()['failed']);
    }

    public function testToolResultFromArrayHydratesTheFailedFlagFromItsKey(): void
    {
        $this->assertFalse(ToolResult::fromArray(['id' => 'id', 'name' => 'name', 'arguments' => [], 'result' => 'val'])->failed);
        $this->assertTrue(ToolResult::fromArray(['id' => 'id', 'name' => 'name', 'arguments' => [], 'result' => 'val', 'failed' => true])->failed);
    }

    public function testToolResultReportsItsErrorOnlyWhenTheCallDidNotSucceed(): void
    {
        $this->assertTrue((new ToolResult('id', 'name', [], 'Berlin'))->successful());
        $this->assertNull((new ToolResult('id', 'name', [], 'Berlin'))->error());
        $this->assertFalse((new ToolResult('id', 'name', [], 'Tool not found', failed: true))->successful());
        $this->assertSame('Tool not found', (new ToolResult('id', 'name', [], 'Tool not found', failed: true))->error());
        $this->assertFalse((new ToolResult('id', 'name', [], 'Rejected', denied: true))->successful());
        $this->assertSame('Rejected', (new ToolResult('id', 'name', [], 'Rejected', denied: true))->error());
        $this->assertNull((new ToolResult('id', 'name', [], ['code' => 500], failed: true))->error());
    }

    public function testToolResultTextSerializesArrayResultsWithoutEscapingSlashesOrUnicode(): void
    {
        $this->assertSame('{"url":"https://example.com/report","city":"Genève"}', (new ToolResult('id', 'name', [], ['url' => 'https://example.com/report', 'city' => 'Genève']))->text());
    }

    public function testToolResultTextPassesThroughStringsAndCastsEverythingElse(): void
    {
        $this->assertSame('https://example.com/report', (new ToolResult('id', 'name', [], 'https://example.com/report'))->text());
        $this->assertSame('72', (new ToolResult('id', 'name', [], 72))->text());
    }
}
