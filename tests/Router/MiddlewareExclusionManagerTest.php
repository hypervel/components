<?php

declare(strict_types=1);

namespace Hypervel\Tests\Router;

use Hypervel\Router\MiddlewareExclusionManager;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class MiddlewareExclusionManagerTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();

        MiddlewareExclusionManager::clear();
    }

    public function testAddAndGetExcluded()
    {
        MiddlewareExclusionManager::addExcluded('http', '/test', 'GET', ['FooMiddleware']);

        $excluded = MiddlewareExclusionManager::get('http', '/test', 'GET');

        $this->assertSame(['FooMiddleware'], $excluded);
    }

    public function testAddMultipleExcluded()
    {
        MiddlewareExclusionManager::addExcluded('http', '/test', 'GET', ['FooMiddleware']);
        MiddlewareExclusionManager::addExcluded('http', '/test', 'GET', ['BarMiddleware']);

        $excluded = MiddlewareExclusionManager::get('http', '/test', 'GET');

        $this->assertSame(['FooMiddleware', 'BarMiddleware'], $excluded);
    }

    public function testGetReturnsEmptyArrayForUnknownRoute()
    {
        $excluded = MiddlewareExclusionManager::get('http', '/unknown', 'GET');

        $this->assertSame([], $excluded);
    }

    public function testMethodIsCaseInsensitive()
    {
        MiddlewareExclusionManager::addExcluded('http', '/test', 'get', ['FooMiddleware']);

        $excluded = MiddlewareExclusionManager::get('http', '/test', 'GET');

        $this->assertSame(['FooMiddleware'], $excluded);
    }

    public function testHeadFallsBackToGet()
    {
        MiddlewareExclusionManager::addExcluded('http', '/test', 'GET', ['FooMiddleware']);

        $excluded = MiddlewareExclusionManager::get('http', '/test', 'HEAD');

        $this->assertSame(['FooMiddleware'], $excluded);
    }

    public function testDifferentServers()
    {
        MiddlewareExclusionManager::addExcluded('http', '/test', 'GET', ['HttpMiddleware']);
        MiddlewareExclusionManager::addExcluded('ws', '/test', 'GET', ['WsMiddleware']);

        $this->assertSame(['HttpMiddleware'], MiddlewareExclusionManager::get('http', '/test', 'GET'));
        $this->assertSame(['WsMiddleware'], MiddlewareExclusionManager::get('ws', '/test', 'GET'));
    }

    public function testDifferentMethods()
    {
        MiddlewareExclusionManager::addExcluded('http', '/test', 'GET', ['GetMiddleware']);
        MiddlewareExclusionManager::addExcluded('http', '/test', 'POST', ['PostMiddleware']);

        $this->assertSame(['GetMiddleware'], MiddlewareExclusionManager::get('http', '/test', 'GET'));
        $this->assertSame(['PostMiddleware'], MiddlewareExclusionManager::get('http', '/test', 'POST'));
    }

    public function testClear()
    {
        MiddlewareExclusionManager::addExcluded('http', '/test', 'GET', ['FooMiddleware']);
        MiddlewareExclusionManager::clear();

        $excluded = MiddlewareExclusionManager::get('http', '/test', 'GET');

        $this->assertSame([], $excluded);
    }

    public function testEmptyArrayIsNotStored()
    {
        MiddlewareExclusionManager::addExcluded('http', '/test', 'GET', []);

        // Container should be empty since we didn't add anything meaningful
        $this->assertSame([], MiddlewareExclusionManager::$container);
    }
}
