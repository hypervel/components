<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support\SupportFacadeTest;

use Hypervel\Container\Container;
use Hypervel\Support\Facades\Facade;
use Hypervel\Support\Testing\Fakes\Fake;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Mockery\MockInterface;
use stdClass;

class SupportFacadeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Facade::clearResolvedInstances();
        FacadeStub::setFacadeApplication(null);
    }

    public function testFacadeCallsUnderlyingApplication(): void
    {
        $app = new ApplicationStub;
        $app->setInstances(['foo' => $mock = m::mock(stdClass::class)]);
        $mock->shouldReceive('bar')->once()->andReturn('baz');
        FacadeStub::setFacadeApplication($app);
        $this->assertSame('baz', FacadeStub::bar());
    }

    public function testShouldReceiveReturnsAMockeryMock(): void
    {
        $app = new ApplicationStub;
        $app->setInstances(['foo' => new stdClass]);
        FacadeStub::setFacadeApplication($app);

        $this->assertInstanceOf(MockInterface::class, $mock = FacadeStub::shouldReceive('foo')->once()->with('bar')->andReturn('baz')->getMock());
        $this->assertSame('baz', $app->make('foo')->foo('bar'));
    }

    public function testSpyReturnsAMockerySpy(): void
    {
        $app = new ApplicationStub;
        $app->setInstances(['foo' => new stdClass]);
        FacadeStub::setFacadeApplication($app);

        $this->assertInstanceOf(MockInterface::class, $spy = FacadeStub::spy());

        FacadeStub::foo();
        $spy->shouldHaveReceived('foo');
    }

    public function testShouldReceiveCanBeCalledTwice(): void
    {
        $app = new ApplicationStub;
        $app->setInstances(['foo' => new stdClass]);
        FacadeStub::setFacadeApplication($app);

        $this->assertInstanceOf(MockInterface::class, FacadeStub::shouldReceive('foo')->once()->with('bar')->andReturn('baz')->getMock());
        $this->assertInstanceOf(MockInterface::class, FacadeStub::shouldReceive('foo2')->once()->with('bar2')->andReturn('baz2')->getMock());
        $this->assertSame('baz', $app->make('foo')->foo('bar'));
        $this->assertSame('baz2', $app->make('foo')->foo2('bar2'));
    }

    public function testCanBeMockedWithoutUnderlyingInstance()
    {
        FacadeStub::shouldReceive('foo')->once()->andReturn('bar');
        $this->assertSame('bar', FacadeStub::foo());
    }

    public function testExpectsReturnsAMockeryMockWithExpectationRequired(): void
    {
        $app = new ApplicationStub;
        $app->setInstances(['foo' => new stdClass]);
        FacadeStub::setFacadeApplication($app);

        $this->assertInstanceOf(MockInterface::class, $mock = FacadeStub::expects('foo')->with('bar')->andReturn('baz')->getMock());
        $this->assertSame('baz', $app->make('foo')->foo('bar'));
    }

    public function testFacadeResolvesAgainAfterClearingSpecific(): void
    {
        $app = new ApplicationStub;
        $app->setInstances(['foo' => $mock = m::mock(stdClass::class)]);
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

    public function testFacadeResolvesAgainAfterClearingAll(): void
    {
        $app = new ApplicationStub;
        $app->setInstances(['foo' => $mock = m::mock(stdClass::class)]);
        $mock->shouldReceive('bar')->times(2)->andReturn('baz');

        // Resolve for the first time
        FacadeStub::setFacadeApplication($app);
        $this->assertSame('baz', FacadeStub::bar());

        // Clear all resolved instances and resolve a second time
        Facade::clearResolvedInstances();
        $this->assertSame('baz', FacadeStub::bar());
    }

    public function testGetFacadeApplicationReturnsSetApplication()
    {
        $this->assertNull(FacadeStub::getFacadeApplication());

        $app = new ApplicationStub;
        FacadeStub::setFacadeApplication($app);

        $this->assertSame($app, FacadeStub::getFacadeApplication());
    }

    public function testSetFacadeApplicationToNullClearsApp()
    {
        $app = new ApplicationStub;
        FacadeStub::setFacadeApplication($app);
        $this->assertSame($app, FacadeStub::getFacadeApplication());

        FacadeStub::setFacadeApplication(null);
        $this->assertNull(FacadeStub::getFacadeApplication());
    }

    public function testSwapSetsInstanceOnApp(): void
    {
        $app = new ApplicationStub;
        $app->setInstances(['foo' => new stdClass]);
        FacadeStub::setFacadeApplication($app);

        $replacement = new stdClass;
        FacadeStub::swap($replacement);

        $this->assertSame($replacement, $app->make('foo'));
        $this->assertSame($replacement, FacadeStub::getFacadeRoot());
    }

    public function testSwapWorksWithoutApp()
    {
        // swap() should not throw when $app is null
        FacadeStub::setFacadeApplication(null);
        $replacement = m::mock(stdClass::class);
        $replacement->shouldReceive('bar')->once()->andReturn('swapped');

        FacadeStub::swap($replacement);

        $this->assertSame('swapped', FacadeStub::bar());
    }

    public function testFacadeReturnsNullWhenAppNotSet()
    {
        FacadeStub::setFacadeApplication(null);
        Facade::clearResolvedInstances();

        $this->assertNull(FacadeStub::getFacadeRoot());
    }

    public function testIsFakeReturnsTrueForFakeInstance()
    {
        $fake = new FakeStub;
        FacadeStub::swap($fake);

        $this->assertTrue(FacadeStub::isFake());
    }

    public function testIsFakeReturnsFalseForNonFakeInstance(): void
    {
        $app = new ApplicationStub;
        $app->setInstances(['foo' => new stdClass]);
        FacadeStub::setFacadeApplication($app);

        $this->assertFalse(FacadeStub::isFake());
    }

    public function testUncachedFacadeResolvesEachTime(): void
    {
        $app = new CountingApplicationStub;
        $app->setInstances(['uncached' => new stdClass]);
        UncachedFacadeStub::setFacadeApplication($app);

        UncachedFacadeStub::getFacadeRoot();
        UncachedFacadeStub::getFacadeRoot();

        // The container should be queried twice since $cached = false.
        $this->assertSame(2, $app->makeCount);
    }
}

class FacadeStub extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'foo';
    }
}

class ApplicationStub extends Container
{
    public function setInstances(array $instances): void
    {
        foreach ($instances as $key => $instance) {
            $this->instance($key, $instance);
        }
    }
}

class FakeStub implements Fake
{
}

class UncachedFacadeStub extends Facade
{
    protected static bool $cached = false;

    protected static function getFacadeAccessor(): string
    {
        return 'uncached';
    }
}

class CountingApplicationStub extends ApplicationStub
{
    public int $makeCount = 0;

    public function make(string $abstract, array $parameters = []): mixed
    {
        ++$this->makeCount;

        return parent::make($abstract, $parameters);
    }
}
