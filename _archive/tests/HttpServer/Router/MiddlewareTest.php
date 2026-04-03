<?php

declare(strict_types=1);

namespace Hypervel\Tests\HttpServer\Router;

use FastRoute\Dispatcher\GroupCountBased;
use Hypervel\HttpServer\MiddlewareManager;
use Hypervel\HttpServer\PriorityMiddleware;
use Hypervel\Tests\HttpServer\Stub\FooMiddleware;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class MiddlewareTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        MiddlewareManager::$container = [];
    }

    // REMOVED: testMiddlewareInController - Tests annotation-based controller middleware registration via handleController(), which was removed (Hypervel uses route files, not annotations)

    // REMOVED: testMiddlewarePriorityInController - Tests annotation-based controller middleware priority via handleController(), which was removed (Hypervel uses route files, not annotations)

    public function testSortMiddlewares()
    {
        $middlewares = [
            'GlobalMiddlewareA',
            'GlobalMiddlewareB' => 1,
            new PriorityMiddleware('HighPriorityMiddleware', 3),
            new PriorityMiddleware('MediumPriorityMiddleware', 1),
        ];

        $sorted = MiddlewareManager::sortMiddlewares($middlewares);

        $this->assertSame([
            'HighPriorityMiddleware',    // priority 3
            'GlobalMiddlewareB',         // priority 1
            'MediumPriorityMiddleware',  // priority 1
            'GlobalMiddlewareA',         // priority 0 (default)
        ], $sorted);
    }

    public function testSortMiddlewaresDeduplicates()
    {
        $middlewares = [
            'FooMiddleware',
            new PriorityMiddleware('FooMiddleware', 5),
        ];

        $sorted = MiddlewareManager::sortMiddlewares($middlewares);

        $this->assertSame(['FooMiddleware'], $sorted);
    }

    public function testFallbackForHead()
    {
        MiddlewareManager::addMiddlewares('http', '/index', 'GET', [FooMiddleware::class]);
        MiddlewareManager::addMiddlewares('http', '/head-register', 'HEAD', []);

        $grouopCountBased = new GroupCountBased([
            [
                'GET' => [
                    '/index' => 'index::handler',
                ],
                'HEAD' => [
                    '/head-register' => 'head-register::handler',
                ],
            ],
            [], // variable route data (none needed)
        ]);
        $this->assertSame([
            GroupCountBased::FOUND,
            'index::handler',
            [],
        ], $grouopCountBased->dispatch('GET', '/index'));
        $this->assertSame([
            GroupCountBased::FOUND,
            'index::handler',
            [],
        ], $grouopCountBased->dispatch('HEAD', '/index'));
        $this->assertSame([
            GroupCountBased::FOUND,
            'head-register::handler',
            [],
        ], $grouopCountBased->dispatch('HEAD', '/head-register'));

        $this->assertSame([FooMiddleware::class], MiddlewareManager::get('http', '/index', 'GET'));
        $this->assertSame([FooMiddleware::class], MiddlewareManager::get('http', '/index', 'HEAD'));
        $this->assertSame([], MiddlewareManager::get('http', '/head-register', 'GET'));
    }
}
