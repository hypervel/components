<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Completions;

use Hypervel\Mcp\Server\Completions\CompletionResponse;
use Hypervel\Tests\TestCase;

class CompletionResponseTest extends TestCase
{
    public function testCreatesACompletionResultWithValues(): void
    {
        $result = CompletionResponse::match(['php', 'python', 'javascript'])->resolve('');

        $this->assertSame(['php', 'python', 'javascript'], $result->values());
        $this->assertFalse($result->hasMore());
    }

    public function testCreatesAnEmptyCompletionResult(): void
    {
        $result = CompletionResponse::empty();

        $this->assertSame([], $result->values());
        $this->assertFalse($result->hasMore());
    }

    public function testAutoTruncatesValuesTo100ItemsAndSetsHasMore(): void
    {
        $values = array_map(fn (int $index): string => "item{$index}", range(1, 150));
        $result = CompletionResponse::match($values)->resolve('');

        $this->assertCount(100, $result->values());
        $this->assertTrue($result->hasMore());
    }

    public function testReturnsRawArrayDataWithoutFilteringUsingAResult(): void
    {
        $result = CompletionResponse::result(['apple', 'apricot', 'banana'])->resolve('ap');

        $this->assertSame(['apple', 'apricot', 'banana'], $result->values());
        $this->assertFalse($result->hasMore());
    }

    public function testAppliesFilteringWithMatchButNotWithResult(): void
    {
        $items = ['apple', 'apricot', 'banana'];

        $matchResult = CompletionResponse::match($items)->resolve('ap');
        $resultResult = CompletionResponse::result($items)->resolve('ap');

        $this->assertSame(['apple', 'apricot'], $matchResult->values());
        $this->assertSame(['apple', 'apricot', 'banana'], $resultResult->values());
    }

    public function testReturnsSingleStringAsArray(): void
    {
        $result = CompletionResponse::result('single-value')->resolve('');

        $this->assertSame(['single-value'], $result->values());
        $this->assertFalse($result->hasMore());
    }

    public function testTruncatesRawArrayDataTo100Items(): void
    {
        $values = array_map(fn (int $index): string => "item{$index}", range(1, 150));
        $result = CompletionResponse::result($values)->resolve('');

        $this->assertCount(100, $result->values());
        $this->assertTrue($result->hasMore());
    }
}
