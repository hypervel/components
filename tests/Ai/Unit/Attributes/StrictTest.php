<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Attributes;

use Hypervel\Ai\Attributes\Strict;
use Hypervel\Tests\Ai\Fixtures\Tools\NonStrictTool;
use Hypervel\Tests\Ai\Fixtures\Tools\RandomNumberGenerator;
use Hypervel\Tests\TestCase;

class StrictTest extends TestCase
{
    public function testIsAppliedToReturnsTrueWhenTargetHasTheAttribute(): void
    {
        $this->assertTrue(Strict::isAppliedTo(new RandomNumberGenerator));
    }

    public function testIsAppliedToReturnsFalseWhenTargetDoesNotHaveTheAttribute(): void
    {
        $this->assertFalse(Strict::isAppliedTo(new NonStrictTool));
    }

    public function testIsAppliedToReturnsFalseWhenTargetIsNull(): void
    {
        $this->assertFalse(Strict::isAppliedTo(null));
    }
}
