<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Attributes;

use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 * @coversNothing
 */
class WithMigrationTest extends TestCase
{
    #[Test]
    public function itCanBeResolved(): void
    {
        $this->assertSame(['hypervel'], (new WithMigration())->types);
        $this->assertSame(['hypervel'], (new WithMigration('queue'))->types);
    }
}
