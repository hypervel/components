<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Container\Container;
use Hypervel\Http\Client\Factory;
use Hypervel\Support\Facades\Facade;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\TestCase;

class SupportFacadesHttpTest extends TestCase
{
    protected Container $app;

    /**
     * Set up the container and facade.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Container;

        // Unbound classes are auto-singletons, so a fresh binding lets these
        // tests observe whether the facade swaps its root into the container.
        $this->app->bind(Factory::class);

        Facade::setFacadeApplication($this->app);
    }

    public function testFacadeRootIsNotSharedByDefault(): void
    {
        $this->assertNotSame(Http::getFacadeRoot(), $this->app->make(Factory::class));
    }

    public function testFacadeRootIsSharedWhenFaked(): void
    {
        Http::fake([
            'https://hypervel.org' => Http::response('OK!'),
        ]);

        $factory = $this->app->make(Factory::class);
        $this->assertSame('OK!', $factory->get('https://hypervel.org')->body());
    }

    public function testFacadeRootIsSharedWhenFakedWithSequence(): void
    {
        Http::fakeSequence('hypervel.org*')->push('OK!');

        $factory = $this->app->make(Factory::class);
        $this->assertSame('OK!', $factory->get('https://hypervel.org')->body());
    }

    public function testFacadeRootIsSharedWhenStubbingUrls(): void
    {
        Http::stubUrl('hypervel.org', Http::response('OK!'));

        $factory = $this->app->make(Factory::class);
        $this->assertSame('OK!', $factory->get('https://hypervel.org')->body());
    }

    public function testFacadeRootIsSharedWhenEnforcingFaking(): void
    {
        $client = Http::preventStrayRequests();

        $this->assertSame($client, $this->app->make(Factory::class));
    }

    public function testFacadeRootIsSharedWhenEnforcingFakingWithAllowedUrls(): void
    {
        $client = Http::preventStrayRequests()->allowStrayRequests(['127.0.0.1']);

        $this->assertSame($client, $this->app->make(Factory::class));
    }

    public function testCanSetPreventsToPreventsStrayRequests(): void
    {
        Http::preventStrayRequests(true);
        $this->assertTrue($this->app->make(Factory::class)->preventingStrayRequests());
        $this->assertTrue(Http::preventingStrayRequests());

        Http::preventStrayRequests(false);
        $this->assertFalse($this->app->make(Factory::class)->preventingStrayRequests());
        $this->assertFalse(Http::preventingStrayRequests());
    }
}
