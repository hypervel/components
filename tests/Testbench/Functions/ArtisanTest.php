<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Functions;

use Hypervel\Console\Command;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Testbench\artisan;

/**
 * @internal
 * @coversNothing
 */
class ArtisanTest extends TestCase
{
    #[Test]
    public function itCanRunArtisanCommand()
    {
        $this->assertSame(Command::SUCCESS, artisan($this, 'env'));
        $this->assertSame(Command::SUCCESS, artisan($this->app, 'env'));
    }
}
