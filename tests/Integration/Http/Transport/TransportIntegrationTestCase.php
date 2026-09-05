<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Transport;

use Hypervel\Foundation\Testing\Concerns\InteractsWithServer;
use Hypervel\Http\Client\Factory;
use Hypervel\Tests\TestCase;

abstract class TransportIntegrationTestCase extends TestCase
{
    use InteractsWithServer;

    /** @var Factory[] */
    private array $factories = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInteractsWithServer();
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->factories as $factory) {
                $factory->purge();
            }
        } finally {
            parent::tearDown();
        }
    }

    /**
     * Create a factory owned by this test.
     */
    protected function factory(): Factory
    {
        return $this->factories[] = new Factory;
    }

    /**
     * Build a URL for this test server.
     */
    protected function serverUrl(string $path = '/', string $scheme = 'http'): string
    {
        return sprintf(
            '%s://%s:%d%s',
            $scheme,
            $this->getServerHost(),
            $this->getServerPort(),
            $path,
        );
    }

    /**
     * Get a TLS fixture path.
     */
    protected function tlsFixture(string $file): string
    {
        return __DIR__ . '/Fixtures/Tls/' . $file;
    }
}
