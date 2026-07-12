<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use RuntimeException;
use Throwable;

/**
 * Provides test server integration testing support.
 *
 * Features:
 * - Opt-in skip: Skips unless TEST_SERVER_HOST is set
 * - Fast-fail: Fails if the configured test server is unreachable
 *
 * Usage: Add `use InteractsWithServer;` to your test case. Declare
 * `protected int $serverPort = 19510;` on the class to set the port.
 * Set TEST_SERVER_HOST to opt into server integration tests.
 */
trait InteractsWithServer
{
    /**
     * Set up server connection check.
     *
     * Server integration tests are opt-in via TEST_SERVER_HOST. The port comes
     * from the test case's $serverPort property.
     */
    protected function setUpInteractsWithServer(): void
    {
        if (env('TEST_SERVER_HOST') === null) {
            $this->markTestSkipped(
                'Set TEST_SERVER_HOST to run server integration tests for ' . static::class
            );
        }

        if (! $this->canConnectToServer()) {
            $this->fail(sprintf(
                'Cannot connect to server at %s:%d. Check your TEST_SERVER_HOST configuration.',
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
     * Get the server host.
     */
    protected function getServerHost(): string
    {
        return (string) env('TEST_SERVER_HOST');
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
