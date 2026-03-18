<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hypervel\Sanctum\TransientToken;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class TransientTokenTest extends TestCase
{
    public function testCanDetermineWhatItCanAndCantDo()
    {
        $token = new TransientToken();

        $this->assertTrue($token->can('foo'));
        $this->assertTrue($token->can('bar'));
        $this->assertFalse($token->cant('foo'));
        $this->assertFalse($token->cant('bar'));
    }
}
