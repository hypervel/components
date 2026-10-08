<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Responses\Data;

use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Tests\TestCase;

class FinishReasonTest extends TestCase
{
    public function testFinishReasonEnumHasExpectedCases(): void
    {
        $this->assertSame('stop', FinishReason::Stop->value);
        $this->assertSame('tool_calls', FinishReason::ToolCalls->value);
        $this->assertSame('length', FinishReason::Length->value);
        $this->assertSame('content_filter', FinishReason::ContentFilter->value);
        $this->assertSame('error', FinishReason::Error->value);
        $this->assertSame('unknown', FinishReason::Unknown->value);
    }
}
