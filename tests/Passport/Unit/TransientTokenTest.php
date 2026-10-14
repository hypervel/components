<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Unit;

use Hypervel\Passport\TransientToken;
use Hypervel\Tests\TestCase;

class TransientTokenTest extends TestCase
{
    public function testTransientTokenCanDoAnything(): void
    {
        $token = new TransientToken;
        $this->assertTrue($token->can('foo'));
        $this->assertFalse($token->cant('foo'));
    }
}
