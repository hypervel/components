<?php

declare(strict_types=1);

namespace Hypervel\Tests\Session\Middleware;

use Hypervel\Session\Middleware\StartSession;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @internal
 * @coversNothing
 */
class StartSessionTest extends TestCase
{
    public function testGetSessionCookieConfigReturnsDefaults(): void
    {
        $middleware = $this->createStartSessionMock();

        $config = $this->invokeGetSessionCookieConfig($middleware, []);

        $this->assertSame('/', $config['path']);
        $this->assertSame('', $config['domain']);
        $this->assertFalse($config['secure']);
        $this->assertTrue($config['http_only']);
        $this->assertNull($config['same_site']);
        $this->assertFalse($config['partitioned']);
    }

    public function testGetSessionCookieConfigReturnsConfiguredValues(): void
    {
        $middleware = $this->createStartSessionMock();

        $config = $this->invokeGetSessionCookieConfig($middleware, [
            'path' => '/app',
            'domain' => '.example.com',
            'secure' => true,
            'http_only' => false,
            'same_site' => 'strict',
            'partitioned' => true,
        ]);

        $this->assertSame('/app', $config['path']);
        $this->assertSame('.example.com', $config['domain']);
        $this->assertTrue($config['secure']);
        $this->assertFalse($config['http_only']);
        $this->assertSame('strict', $config['same_site']);
        $this->assertTrue($config['partitioned']);
    }

    public function testGetSessionCookieConfigCanBeOverridden(): void
    {
        $middleware = new CustomStartSession();

        $config = $this->invokeGetSessionCookieConfig($middleware, [
            'path' => '/',
            'domain' => '.example.com',
        ]);

        // Custom middleware overrides domain
        $this->assertSame('.custom.example.com', $config['domain']);
        // Other values come from config
        $this->assertSame('/', $config['path']);
    }

    private function createStartSessionMock(): StartSession
    {
        return $this->getMockBuilder(StartSession::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    private function invokeGetSessionCookieConfig(StartSession $middleware, array $config): array
    {
        $method = new ReflectionMethod($middleware, 'getSessionCookieConfig');
        $method->setAccessible(true);

        return $method->invoke($middleware, $config);
    }
}

/**
 * Custom middleware for testing getSessionCookieConfig override.
 */
class CustomStartSession extends StartSession
{
    public function __construct()
    {
        // Skip parent constructor for testing
    }

    protected function getSessionCookieConfig(array $config): array
    {
        $cookieConfig = parent::getSessionCookieConfig($config);

        // Override domain
        $cookieConfig['domain'] = '.custom.example.com';

        return $cookieConfig;
    }
}
