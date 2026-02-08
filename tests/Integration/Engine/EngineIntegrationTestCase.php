<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Engine;

use Hypervel\Foundation\Testing\Concerns\InteractsWithServer;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Tests\TestCase;

/**
 * Base test case for engine integration tests that require a test server.
 *
 * @internal
 * @coversNothing
 */
abstract class EngineIntegrationTestCase extends TestCase
{
    use InteractsWithServer;
    use RunTestsInCoroutine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInteractsWithServer();
    }
}
