<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Testbench\TestCase;

class ReloadApplicationTest extends TestCase
{
    protected int $setUpCalls = 0;

    protected int $tearDownCalls = 0;

    /**
     * @var list<array{string, ApplicationContract}>
     */
    protected array $refreshed = [];

    /**
     * Register a refreshed callback before the application is created.
     */
    protected function setUp(): void
    {
        ++$this->setUpCalls;

        $this->afterApplicationRefreshed(function (): void {
            $this->refreshed[] = ['setUp', $this->app];
        });

        parent::setUp();
    }

    /**
     * Count the test case's own teardown calls.
     */
    protected function tearDown(): void
    {
        ++$this->tearDownCalls;

        parent::tearDown();
    }

    public function testReloadRunsRefreshedCallbacksAgainWithoutRerunningTheTestCaseLifecycle(): void
    {
        $original = $this->app;

        $this->afterApplicationRefreshed(function (): void {
            $this->refreshed[] = ['body', $this->app];
        });

        $this->assertSame([['setUp', $original], ['body', $original]], $this->refreshed);

        $this->reloadApplication();

        $this->assertNotSame($original, $this->app);
        $this->assertSame([
            ['setUp', $original],
            ['body', $original],
            ['setUp', $this->app],
            ['body', $this->app],
        ], $this->refreshed);
        $this->assertSame(1, $this->setUpCalls);
        $this->assertSame(0, $this->tearDownCalls);
    }
}
