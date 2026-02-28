<?php

declare(strict_types=1);

namespace Hypervel\Tests\ExceptionHandler;

use Hypervel\Config\Repository;
use Hypervel\ExceptionHandler\Listener\ExceptionHandlerListener;
use Hypervel\Framework\Events\BootApplication;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ExceptionHandlerListenerTest extends TestCase
{
    public function testConfig()
    {
        $config = new Repository([
            'exceptions' => [
                'handler' => [
                    'http' => $http = [
                        'Foo', 'Bar',
                    ],
                    'ws' => $ws = [
                        'Foo', 'Tar', 'Bar',
                    ],
                ],
            ],
        ]);
        $listener = new ExceptionHandlerListener($config);
        $listener->handle(new BootApplication());
        $this->assertSame($http, $config->get('exceptions.handler', [])['http']);
        $this->assertSame($ws, $config->get('exceptions.handler', [])['ws']);
    }

    public function testDuplicateHandlersAreDeduped()
    {
        $config = new Repository([
            'exceptions' => [
                'handler' => [
                    'http' => [
                        'Foo', 'Bar', 'Bar', 'Tar',
                    ],
                ],
            ],
        ]);
        $listener = new ExceptionHandlerListener($config);
        $listener->handle(new BootApplication());
        $this->assertSame([
            'http' => [
                'Foo', 'Bar', 'Tar',
            ],
        ], $config->get('exceptions.handler', []));
    }

    public function testHandlersWithPriority()
    {
        $config = new Repository([
            'exceptions' => [
                'handler' => [
                    'http' => [
                        'Foo' => 0,
                        'Bar' => 1,
                    ],
                ],
            ],
        ]);
        $listener = new ExceptionHandlerListener($config);
        $listener->handle(new BootApplication());
        $this->assertSame([
            'http' => [
                'Bar', 'Foo',
            ],
        ], $config->get('exceptions.handler', []));
    }

    public function testEmptyConfig()
    {
        $config = new Repository([]);
        $listener = new ExceptionHandlerListener($config);
        $listener->handle(new BootApplication());
        $this->assertSame([], $config->get('exceptions.handler', []));
    }

    public function testMultipleServers()
    {
        $config = new Repository([
            'exceptions' => [
                'handler' => [
                    'http' => [
                        'HttpHandler' => 1,
                        'SharedHandler' => 0,
                    ],
                    'ws' => [
                        'WsHandler' => 1,
                        'SharedHandler' => 0,
                    ],
                ],
            ],
        ]);
        $listener = new ExceptionHandlerListener($config);
        $listener->handle(new BootApplication());
        $result = $config->get('exceptions.handler', []);
        $this->assertSame(['HttpHandler', 'SharedHandler'], $result['http']);
        $this->assertSame(['WsHandler', 'SharedHandler'], $result['ws']);
    }
}
