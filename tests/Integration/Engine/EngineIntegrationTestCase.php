<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Engine;

use Hypervel\Foundation\Testing\Concerns\InteractsWithHttpServer;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Tests\TestCase;

/**
 * Base test case for engine integration tests that require an HTTP server.
 *
 * @internal
 * @coversNothing
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
}
