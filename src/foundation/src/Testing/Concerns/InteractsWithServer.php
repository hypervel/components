<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use RuntimeException;
use Throwable;

/**
 * Provides test server integration testing support.
 *
 * Features:
 * - Auto-skip: Skips tests if server unavailable on configured host/port
 * - Fast-fail: Once connection fails with defaults, all subsequent tests skip immediately
 *
 * Usage: Add `use InteractsWithServer;` to your test case. Declare
 * `protected int $serverPort = 19510;` on the class to set the port.
 * The host defaults to 127.0.0.1 and can be overridden via the
 * ENGINE_TEST_SERVER_HOST environment variable.
 */
trait InteractsWithServer
{
    /**
     * Indicates if connection failed once, skip all subsequent tests.
     */
    private static bool $serverConnectionFailed = false;

    /**
     * Set up server connection check.
     *
     * Checks if the server is reachable on the configured host/port.
     * If not reachable and using default host, skips the test.
     * If not reachable and using explicit host, fails the test (misconfiguration).
     */
    protected function setUpInteractsWithServer(): void
    {
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
                $this->getServerHost(),
                $this->getServerPort(),
            ));
        }
    }

    /**
     * Check if we can connect to the server.
     */
    protected function canConnectToServer(): bool
    {
        try {
            $socket = @fsockopen($this->getServerHost(), $this->getServerPort(), $errno, $errstr, 1);
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
        return $this->serverHost ?? env('ENGINE_TEST_SERVER_HOST', '127.0.0.1');
    }

    /**
     * Get the server port.
     *
     * Classes using this trait must declare `protected int $serverPort`.
     */
    protected function getServerPort(): int
    {
        if (! isset($this->serverPort)) {
            throw new RuntimeException(static::class . ' uses InteractsWithServer but does not declare a $serverPort property.');
        }

        return $this->serverPort;
    }
}
