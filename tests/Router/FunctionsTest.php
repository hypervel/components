<?php

declare(strict_types=1);

namespace Hypervel\Tests\Router;

use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ContainerInterface;
use Hypervel\Contracts\Router\UrlGenerator as UrlGeneratorContract;
use Hypervel\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;

use function Hypervel\Router\route;
use function Hypervel\Router\secure_url;
use function Hypervel\Router\url;

/**
 * @internal
 * @coversNothing
 */
class FunctionsTest extends TestCase
{
    public function testRoute()
    {
        $urlGenerator = $this->mockUrlGenerator();

        $urlGenerator->shouldReceive('route')
            ->with('foo', ['bar'], true, 'http')
            ->andReturn('foo-bar');

        $urlGenerator->shouldReceive('route')
            ->with('foo', ['bar'], true, 'baz')
            ->andReturn('foo-bar-baz');

        $this->assertEquals('foo-bar', route('foo', ['bar']));
        $this->assertEquals('foo-bar-baz', route('foo', ['bar'], true, 'baz'));
    }

    public function testUrl()
    {
        $urlGenerator = $this->mockUrlGenerator();

        $urlGenerator->shouldReceive('to')
            ->with('foo', ['bar'], true)
            ->andReturn('foo-bar');

        $this->assertEquals('foo-bar', url('foo', ['bar'], true));
    }

    public function testSecureUrl()
    {
        $urlGenerator = $this->mockUrlGenerator();

        $urlGenerator->shouldReceive('secure')
            ->with('foo', ['bar'])
            ->andReturn('foo-bar');

        $this->assertEquals('foo-bar', secure_url('foo', ['bar']));
    }

    /**
     * @return MockInterface|UrlGenerator
     */
    private function mockUrlGenerator(): UrlGeneratorContract
    {
        /** @var ContainerInterface|MockInterface */
        $container = Mockery::mock(ContainerInterface::class);
        $urlGenerator = Mockery::mock(UrlGeneratorContract::class);

        $container->shouldReceive('get')
            ->with(UrlGeneratorContract::class)
            ->andReturn($urlGenerator);

        ApplicationContext::setContainer($container);

        return $urlGenerator;
    }
}
