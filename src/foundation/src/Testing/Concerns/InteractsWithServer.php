<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Throwable;

/**
 * Provides test server integration testing support.
 *
 * Features:
 * - Auto-skip: Skips tests if server unavailable on configured host/port
 * - Fast-fail: Once connection fails with defaults, all subsequent tests skip immediately
 *
 * Usage: Add `use InteractsWithServer;` to your test case. Override $serverPort
 * in your test class to connect to a different server port.
 *
 * Environment Variables:
 * - ENGINE_TEST_SERVER_HOST: Server host (default: 127.0.0.1)
 */
trait InteractsWithServer
{
    /**
     * Indicates if connection failed once, skip all subsequent tests.
     */
    private static bool $serverConnectionFailed = false;

    /**
     * The server host for testing.
     */
    protected string $serverHost = '127.0.0.1';

    /**
     * The server port for testing.
     *
     * Override this in your test class to connect to a different port.
     */
    protected int $serverPort = 19501;

    /**
     * Set up server connection check.
     *
     * Checks if the server is reachable on the configured host/port.
     * If not reachable and using default host, skips the test.
     * If not reachable and using explicit host, fails the test (misconfiguration).
     */
    protected function setUpInteractsWithServer(): void
    {
        // Only override host from env, not port - each test class sets its own port
        $this->serverHost = env('ENGINE_TEST_SERVER_HOST', '127.0.0.1');

        if (static::$serverConnectionFailed) {
            $this->markTestSkipped(
                'Server connection failed with defaults. Set ENGINE_TEST_SERVER_HOST to enable ' . static::class
            );
        }

        if (! $this->canConnectToServer()) {
            if ($this->isUsingDefaultServerConfig()) {
                static::$serverConnectionFailed = true;
                $this->markTestSkipped(
                    'Server connection failed with defaults. Set ENGINE_TEST_SERVER_HOST to enable ' . static::class
                );
            }
            // Explicit config exists but failed - throw so test fails (misconfiguration)
            $this->fail(sprintf(
                'Cannot connect to server at %s:%d. Check your ENGINE_TEST_SERVER_HOST configuration.',
                $this->serverHost,
                $this->serverPort
            ));
        }
    }

    /**
     * Check if we can connect to the server.
     */
    protected function canConnectToServer(): bool
    {
        try {
            $socket = @fsockopen($this->serverHost, $this->serverPort, $errno, $errstr, 1);
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
     * Check if using default server configuration.
     */
    protected function isUsingDefaultServerConfig(): bool
    {
        return env('ENGINE_TEST_SERVER_HOST') === null;
    }

    /**
     * Get the server host.
     */
    protected function getServerHost(): string
    {
        return $this->serverHost;
    }

    /**
     * Get the server port.
     */
    protected function getServerPort(): int
    {
        return $this->serverPort;
    }
}
