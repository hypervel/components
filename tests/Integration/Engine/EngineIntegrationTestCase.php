<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Engine;

use Hypervel\Foundation\Testing\Concerns\InteractsWithServer;
use Hypervel\Tests\TestCase;

/**
 * Base test case for engine integration tests that require a test server.
 */
abstract class EngineIntegrationTestCase extends TestCase
{
    use InteractsWithServer;

    protected int $serverPort = 19501;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInteractsWithServer();
    }
}
