<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Carbon\Carbon;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Testbench\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 * @coversNothing
 */
class TimezoneTest extends TestCase
{
    #[Override]
    protected function getApplicationTimezone(ApplicationContract $app): ?string
    {
        return 'Asia/Kuala_Lumpur';
    }

    #[Test]
    public function itCanOverrideTimezone(): void
    {
        $this->assertSame('Asia/Kuala_Lumpur', Carbon::now()->timezoneName);
    }
}
