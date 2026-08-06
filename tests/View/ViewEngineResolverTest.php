<?php

declare(strict_types=1);

namespace Hypervel\Tests\View;

use Hypervel\Contracts\View\Engine;
use Hypervel\Tests\TestCase;
use Hypervel\View\Engines\EngineResolver;
use InvalidArgumentException;

class ViewEngineResolverTest extends TestCase
{
    public function testResolversMayBeResolved(): void
    {
        $resolver = new EngineResolver;
        $resolver->register('foo', function () {
            return new FakeEngine;
        });
        $result = $resolver->resolve('foo');

        $this->assertTrue($result === $resolver->resolve('foo'));
    }

    public function testResolverThrowsExceptionOnUnknownEngine(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $resolver = new EngineResolver;
        $resolver->resolve('foo');
    }

    public function testRegisteringAResolverForAResolvedEngineForgetsThePriorInstance(): void
    {
        $resolver = new EngineResolver;
        $first = new FakeEngine;
        $second = new FakeEngine;

        $resolver->register('foo', fn () => $first);
        $this->assertSame($first, $resolver->resolve('foo'));

        $resolver->register('foo', fn () => $second);

        $this->assertSame($second, $resolver->resolve('foo'));
    }
}

class FakeEngine implements Engine
{
    public function get(string $path, array $data = []): string
    {
        return '';
    }
}
