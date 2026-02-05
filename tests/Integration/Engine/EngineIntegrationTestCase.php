<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Engine;

use Hypervel\Foundation\Testing\Concerns\InteractsWithHttpServer;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Base test case for engine integration tests that require an HTTP server.
 *
 * Uses InteractsWithHttpServer to auto-skip when server is unavailable.
 */
abstract class EngineIntegrationTestCase extends TestCase
{
    use InteractsWithHttpServer;
    use RunTestsInCoroutine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInteractsWithHttpServer();
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }
}
