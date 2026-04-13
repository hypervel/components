<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Integrations;

use Hypervel\Testbench\Concerns\WithWorkbench;
use Hypervel\Tests\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 * @coversNothing
 */
class CommandTest extends TestCase
{
    use WithWorkbench;

    #[Test]
    public function itCanShowExpectedOutput()
    {
        $this->artisan('sample:command')
            ->expectsOutput('It works!')
            ->run();
    }
}
