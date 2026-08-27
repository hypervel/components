<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Prompt;
use Hypervel\Tests\TestCase;

class EraseTest extends TestCase
{
    public function testEraseLinesWritesExactMovementForPositiveCountsOnly(): void
    {
        Prompt::fake();

        $prompt = new class extends Prompt {
            public function value(): mixed
            {
                return null;
            }
        };

        $prompt->eraseLines(-1);
        $prompt->eraseLines(0);

        $this->assertSame('', Prompt::content());

        $prompt->eraseLines(1);

        $this->assertSame("\e[2K\e[G", Prompt::content());

        $prompt->eraseLines(3);

        $this->assertSame(
            "\e[2K\e[G\e[2K\e[1A\e[2K\e[1A\e[2K\e[G",
            Prompt::content(),
        );
    }
}
