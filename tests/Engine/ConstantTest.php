<?php

declare(strict_types=1);

namespace Hypervel\Tests\Engine;

use Hypervel\Engine\Constant;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ConstantTest extends TestCase
{
    public function testEngine()
    {
        $this->assertSame('Swoole', Constant::ENGINE);
    }
}
