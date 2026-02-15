<?php

declare(strict_types=1);

namespace Hypervel\Tests\View;

use Hypervel\View\Contracts\Engine;
use Hypervel\View\Engines\EngineResolver;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ViewEngineResolverTest extends TestCase
{
    public function testResolversMayBeResolved()
    {
        $resolver = new EngineResolver();
        $resolver->register('foo', function () {
            return new FakeEngine();
        });
        $result = $resolver->resolve('foo');

        $this->assertTrue($result === $resolver->resolve('foo'));
    }

    public function testResolverThrowsExceptionOnUnknownEngine()
    {
        $this->expectException(InvalidArgumentException::class);

        $resolver = new EngineResolver();
        $resolver->resolve('foo');
    }
}

class FakeEngine implements Engine
{
    public function get(string $path, array $data = []): string
    {
        return '';
    }
}
