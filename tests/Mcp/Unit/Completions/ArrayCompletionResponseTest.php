<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Completions;

use Hypervel\Mcp\Server\Completions\ArrayCompletionResponse;
use Hypervel\Mcp\Server\Completions\DirectCompletionResponse;
use Hypervel\Tests\TestCase;

class ArrayCompletionResponseTest extends TestCase
{
    public function testFiltersByPrefixWhenResolved(): void
    {
        $result = new ArrayCompletionResponse(['php', 'python', 'javascript', 'go']);

        $resolved = $result->resolve('py');

        $this->assertInstanceOf(DirectCompletionResponse::class, $resolved);
        $this->assertSame(['python'], $resolved->values());
    }

    public function testStartsWithEmptyValuesUntilResolved(): void
    {
        $result = new ArrayCompletionResponse(['php', 'python', 'javascript']);

        $this->assertSame([], $result->values());
        $this->assertFalse($result->hasMore());
    }
}
