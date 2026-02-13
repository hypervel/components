<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Guzzle;

use Hypervel\Foundation\Testing\Concerns\InteractsWithServer;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Tests\TestCase;

/**
 * Base test case for Guzzle integration tests that require a test server.
 *
 * @internal
 * @coversNothing
 */
abstract class GuzzleIntegrationTestCase extends TestCase
{
    use InteractsWithServer;
    use RunTestsInCoroutine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInteractsWithServer();
    }
}
