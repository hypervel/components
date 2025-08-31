<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hypervel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class PersonalAccessTokenTest extends TestCase
{
    public function testCanDetermineWhatItCanAndCantDo(): void
    {
        $token = new PersonalAccessToken();

        $token->abilities = [];

        $this->assertFalse($token->can('foo'));

        $token->abilities = ['foo'];

        $this->assertTrue($token->can('foo'));
        $this->assertFalse($token->can('bar'));
        $this->assertTrue($token->cant('bar'));
        $this->assertFalse($token->cant('foo'));

        $token->abilities = ['foo', '*'];

        $this->assertTrue($token->can('foo'));
        $this->assertTrue($token->can('bar'));
    }
}
