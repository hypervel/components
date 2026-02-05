<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Throwable;

/**
 * Provides HTTP server integration testing support for engine tests.
 *
 * Auto-called by TestCase via setUpTraits():
 * - setUpInteractsWithHttpServer() runs after app boots
 *
 * Features:
 * - Auto-skip: Skips tests if HTTP server unavailable on configured host/port
 * - Fast-fail: Once connection fails with defaults, all subsequent tests skip immediately
 *
 * Usage: Add `use InteractsWithHttpServer;` to your test case.
 *
 * Environment Variables:
 * - ENGINE_TEST_SERVER_HOST: Server host (default: 127.0.0.1)
 * - ENGINE_TEST_SERVER_PORT: Server port (default: 9501)
 */
trait InteractsWithHttpServer
{
    /**
     * Indicates if connection failed once, skip all subsequent tests.
     */
    private static bool $httpServerConnectionFailed = false;

    /**
     * The HTTP server host for testing.
     */
    protected string $httpServerHost = '127.0.0.1';

    /**
     * The HTTP server port for testing.
     */
    protected int $httpServerPort = 9501;

    /**
     * Set up HTTP server connection check (auto-called by setUpTraits).
     *
     * Follows the same pattern as InteractsWithMeilisearch:
     * - Only skips if using default host/port AND no explicit env var
     * - If explicit config exists and fails, the exception propagates (misconfiguration)
     */
    protected function setUpInteractsWithHttpServer(): void
    {
        $this->httpServerHost = env('ENGINE_TEST_SERVER_HOST', '127.0.0.1');
        $this->httpServerPort = (int) env('ENGINE_TEST_SERVER_PORT', 9501);

        if (static::$httpServerConnectionFailed) {
            $this->markTestSkipped(
                'HTTP server connection failed with defaults. Set ENGINE_TEST_SERVER_HOST & ENGINE_TEST_SERVER_PORT to enable ' . static::class
            );
        }

        if (! $this->canConnectToHttpServer()) {
            if ($this->isUsingDefaultHttpServerConfig()) {
                static::$httpServerConnectionFailed = true;
                $this->markTestSkipped(
                    'HTTP server connection failed with defaults. Set ENGINE_TEST_SERVER_HOST & ENGINE_TEST_SERVER_PORT to enable ' . static::class
                );
            }
            // Explicit config exists but failed - throw so test fails (misconfiguration)
            $this->fail(sprintf(
                'Cannot connect to HTTP server at %s:%d. Check your ENGINE_TEST_SERVER_HOST and ENGINE_TEST_SERVER_PORT configuration.',
                $this->httpServerHost,
                $this->httpServerPort
            ));
        }
    }

    /**
     * Check if we can connect to the HTTP server.
     */
    protected function canConnectToHttpServer(): bool
    {
        try {
            $socket = @fsockopen($this->httpServerHost, $this->httpServerPort, $errno, $errstr, 1);
            if ($socket === false) {
                return false;
            }
            fclose($socket);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Check if using default HTTP server configuration.
     */
    protected function isUsingDefaultHttpServerConfig(): bool
    {
        return env('ENGINE_TEST_SERVER_HOST') === null
            && env('ENGINE_TEST_SERVER_PORT') === null;
    }

    /**
     * Get the HTTP server host.
     */
    protected function getHttpServerHost(): string
    {
        return $this->httpServerHost;
    }

    /**
     * Get the HTTP server port.
     */
    protected function getHttpServerPort(): int
    {
        return $this->httpServerPort;
    }
}
