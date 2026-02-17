<?php

declare(strict_types=1);

namespace Hypervel\Tests\ExceptionHandler;

use Hyperf\Di\Annotation\AnnotationCollector;
use Hypervel\Config\Repository;
use Hypervel\ExceptionHandler\Annotation\ExceptionHandler;
use Hypervel\ExceptionHandler\Listener\ExceptionHandlerListener;
use Hypervel\Tests\TestCase;
use stdClass;

/**
 * @internal
 * @coversNothing
 */
class ExceptionHandlerListenerTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();

        AnnotationCollector::clear();
    }

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
        $listener->process(new stdClass());
        $this->assertSame($http, $config->get('exceptions.handler', [])['http']);
        $this->assertSame($ws, $config->get('exceptions.handler', [])['ws']);
    }

    public function testAnnotation()
    {
        $config = new Repository([
            'exceptions' => [
                'handler' => [
                    'http' => [
                        'Foo', 'Bar',
                    ],
                ],
            ],
        ]);
        AnnotationCollector::collectClass('Bar1', ExceptionHandler::class, new ExceptionHandler('http', 1));
        $listener = new ExceptionHandlerListener($config);
        $listener->process(new stdClass());
        $this->assertSame([
            'http' => [
                'Bar1', 'Foo', 'Bar',
            ],
        ], $config->get('exceptions.handler', []));
    }

    public function testAnnotationWithSamePriotity()
    {
        $config = new Repository([
            'exceptions' => [
                'handler' => [
                    'http' => [
                        'Foo', 'Bar',
                    ],
                    'ws' => [
                        'Foo',
                    ],
                ],
            ],
        ]);
        AnnotationCollector::collectClass('Bar1', ExceptionHandler::class, new ExceptionHandler('http', 0));
        AnnotationCollector::collectClass('Bar', ExceptionHandler::class, new ExceptionHandler('ws', 1));
        $listener = new ExceptionHandlerListener($config);
        $listener->process(new stdClass());
        $this->assertEquals(['Foo', 'Bar', 'Bar1'], $config->get('exceptions.handler', [])['http']);
        $this->assertEquals(['Bar', 'Foo'], $config->get('exceptions.handler', [])['ws']);
    }

    public function testTheSameHandler()
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
        AnnotationCollector::collectClass('Tar', ExceptionHandler::class, new ExceptionHandler('http', 1));
        $listener = new ExceptionHandlerListener($config);
        $listener->process(new stdClass());
        $this->assertSame([
            'http' => [
                'Tar', 'Foo', 'Bar',
            ],
        ], $config->get('exceptions.handler', []));
    }
}
