<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Hypervel\Testbench\TestCase;

trait RegistersRefreshedCallbackDuringSetUp
{
    /**
     * Register a refreshed callback while the test case's traits are set up.
     */
    protected function setUpRegistersRefreshedCallbackDuringSetUp(): void
    {
        $this->hooks[] = 'trait';

        $this->afterApplicationRefreshed(function (): void {
            $this->hooks[] = 'trait refreshed';
        });
    }
}

class ApplicationTestingHooksTest extends TestCase
{
    use RegistersRefreshedCallbackDuringSetUp;

    /**
     * @var list<string>
     */
    protected array $hooks = [];

    /**
     * Register the application callbacks before the application is created.
     */
    protected function setUp(): void
    {
        $this->afterApplicationRefreshed(function (): void {
            $this->hooks[] = 'refreshed';
        });

        $this->afterApplicationCreated(function (): void {
            $this->hooks[] = 'created';
        });

        parent::setUp();
    }

    public function testRefreshedCallbacksRunBeforeTraitsAndCreatedCallbacks(): void
    {
        $this->assertSame(['refreshed', 'trait', 'trait refreshed', 'created'], $this->hooks);
    }

    public function testDestroyedCallbacksRunNewestFirst(): void
    {
        $destroyed = [];

        $this->beforeApplicationDestroyed(function () use (&$destroyed): void {
            $this->assertSame(['newest', 'middle'], $destroyed);
        });

        $this->beforeApplicationDestroyed(function () use (&$destroyed): void {
            $destroyed[] = 'middle';
        });

        $this->beforeApplicationDestroyed(function () use (&$destroyed): void {
            $destroyed[] = 'newest';
        });
    }
}
