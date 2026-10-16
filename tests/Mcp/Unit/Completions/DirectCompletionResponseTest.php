<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Completions;

use Hypervel\Mcp\Server\Completions\DirectCompletionResponse;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class DirectCompletionResponseTest extends TestCase
{
    public function testReturnsSelfWhenResolved(): void
    {
        $result = new DirectCompletionResponse(['php', 'python']);

        $resolved = $result->resolve('py');

        $this->assertSame($result, $resolved);
    }

    public function testThrowsExceptionWhenTheConstructorReceivesMoreThan100Items(): void
    {
        $values = array_map(fn (int $index): string => "item{$index}", range(1, 101));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Completion values cannot exceed 100 items');
        new DirectCompletionResponse($values);
    }

    public function testDoesNotAllowMoreThan100Items(): void
    {
        $values = array_map(fn (int $index): string => "item{$index}", range(1, 100));
        $result = new DirectCompletionResponse($values);

        $this->assertCount(100, $result->values());
    }
}
