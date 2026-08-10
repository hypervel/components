<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Functions;

use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Testbench\join_paths;

class JoinPathsTest extends TestCase
{
    #[Test]
    public function itPreservesAZeroPathSegment(): void
    {
        $this->assertSame(
            DIRECTORY_SEPARATOR . 'base' . DIRECTORY_SEPARATOR . '0' . DIRECTORY_SEPARATOR . 'file',
            join_paths(DIRECTORY_SEPARATOR . 'base', '0', 'file'),
        );
    }
}
