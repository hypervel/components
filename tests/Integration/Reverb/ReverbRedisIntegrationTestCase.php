<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Reverb;

/**
 * Base test case for Reverb integration tests with Redis scaling enabled.
 *
 * Requires a running Redis-enabled Reverb test server on port 19511.
 * Start it with: REVERB_SERVER_PORT=19511 REVERB_SCALING_ENABLED=true php tests/Integration/Reverb/server.php
 *
 * Tests auto-skip when the server is unavailable (InteractsWithServer
 * from the parent class handles this). Redis availability is implicitly
 * gated because the Redis test server requires Redis to start.
 */
abstract class ReverbRedisIntegrationTestCase extends ReverbIntegrationTestCase
{
    protected int $serverPort = 19511;
}
