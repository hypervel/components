<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use ArrayAccess;
use Hypervel\Support\Facades\Facade;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Mockery\MockInterface;
use stdClass;

/**
 * @internal
 * @coversNothing
 */
class SupportFacadeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Facade::clearResolvedInstances();
        FacadeStub::setFacadeApplication(null);
    }

    public function testFacadeCallsUnderlyingApplication()
    {
        $app = new ApplicationStub;
        $app->setAttributes(['foo' => $mock = m::mock(stdClass::class)]);
        $mock->shouldReceive('bar')->once()->andReturn('baz');
        FacadeStub::setFacadeApplication($app);
        $this->assertSame('baz', FacadeStub::bar());
    }

    public function testShouldReceiveReturnsAMockeryMock()
    {
        $app = new ApplicationStub;
        $app->setAttributes(['foo' => new stdClass]);
        FacadeStub::setFacadeApplication($app);

        $this->assertInstanceOf(MockInterface::class, $mock = FacadeStub::shouldReceive('foo')->once()->with('bar')->andReturn('baz')->getMock());
        $this->assertSame('baz', $app['foo']->foo('bar'));
    }

    public function testSpyReturnsAMockerySpy()
    {
        $app = new ApplicationStub;
        $app->setAttributes(['foo' => new stdClass]);
        FacadeStub::setFacadeApplication($app);

        $this->assertInstanceOf(MockInterface::class, $spy = FacadeStub::spy());

        FacadeStub::foo();
        $spy->shouldHaveReceived('foo');
    }

    public function testShouldReceiveCanBeCalledTwice()
    {
        $app = new ApplicationStub;
        $app->setAttributes(['foo' => new stdClass]);
        FacadeStub::setFacadeApplication($app);

        $this->assertInstanceOf(MockInterface::class, $mock = FacadeStub::shouldReceive('foo')->once()->with('bar')->andReturn('baz')->getMock());
        $this->assertInstanceOf(MockInterface::class, $mock = FacadeStub::shouldReceive('foo2')->once()->with('bar2')->andReturn('baz2')->getMock());
        $this->assertSame('baz', $app['foo']->foo('bar'));
        $this->assertSame('baz2', $app['foo']->foo2('bar2'));
    }

    public function testCanBeMockedWithoutUnderlyingInstance()
    {
        FacadeStub::shouldReceive('foo')->once()->andReturn('bar');
        $this->assertSame('bar', FacadeStub::foo());
    }

    public function testExpectsReturnsAMockeryMockWithExpectationRequired()
    {
        $app = new ApplicationStub;
        $app->setAttributes(['foo' => new stdClass]);
        FacadeStub::setFacadeApplication($app);

        $this->assertInstanceOf(MockInterface::class, $mock = FacadeStub::expects('foo')->with('bar')->andReturn('baz')->getMock());
        $this->assertSame('baz', $app['foo']->foo('bar'));
    }

    public function testFacadeResolvesAgainAfterClearingSpecific()
    {
        $app = new ApplicationStub;
        $app->setAttributes(['foo' => $mock = m::mock(stdClass::class)]);
        $mock->shouldReceive('bar')->times(3)->andReturn('baz');

        // Resolve for the first time
        FacadeStub::setFacadeApplication($app);
        $this->assertSame('baz', FacadeStub::bar());

        // Clear resolved instance and resolve the second time
        FacadeStub::clearResolvedInstance();
        $this->assertSame('baz', FacadeStub::bar());

        // Clear resolved instance through parent and resolve the third time
        Facade::clearResolvedInstance('foo');
        $this->assertSame('baz', FacadeStub::bar());
    }

    public function testFacadeResolvesAgainAfterClearingAll()
    {
        $app = new ApplicationStub;
        $app->setAttributes(['foo' => $mock = m::mock(stdClass::class)]);
        $mock->shouldReceive('bar')->times(2)->andReturn('baz');

        // Resolve for the first time
        FacadeStub::setFacadeApplication($app);
        $this->assertSame('baz', FacadeStub::bar());

        // Clear all resolved instances and resolve a second time
        Facade::clearResolvedInstances();
        $this->assertSame('baz', FacadeStub::bar());
    }
}

class FacadeStub extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'foo';
    }
}

class ApplicationStub implements ArrayAccess
{
    protected array $attributes = [];

    public function setAttributes(array $attributes): void
    {
        $this->attributes = $attributes;
    }

    public function instance(string $key, mixed $instance): void
    {
        $this->attributes[$key] = $instance;
    }

    public function offsetExists($offset): bool
    {
        return isset($this->attributes[$offset]);
    }

    public function offsetGet($key): mixed
    {
        return $this->attributes[$key];
    }

    public function offsetSet($key, $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function offsetUnset($key): void
    {
        unset($this->attributes[$key]);
    }
}
