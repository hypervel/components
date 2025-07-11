<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hypervel\Sanctum\Exceptions\MissingAbilityException;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class MissingAbilityExceptionTest extends TestCase
{
    public function testAbilitiesMethodReturnsTheAbilities(): void
    {
        $exception = new MissingAbilityException(['foo', 'bar']);

        $this->assertEquals(['foo', 'bar'], $exception->abilities());
    }

    public function testAbilitiesMethodWithStringAbility(): void
    {
        $exception = new MissingAbilityException('foo');

        $this->assertEquals(['foo'], $exception->abilities());
    }
}