<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Configuration;

use Exception;
use Hypervel\Container\Container;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Foundation\Configuration\Exceptions;
use Hypervel\Foundation\Exceptions\Handler;
use Hypervel\Http\Request;
use Hypervel\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ExceptionsTest extends TestCase
{
    public function testStopIgnoring()
    {
        $container = new Container;
        $exceptions = new Exceptions($handler = new class($container) extends Handler {
            public function getDontReport(): array
            {
                return array_merge($this->dontReport, $this->internalDontReport);
            }
        });

        $this->assertContains(HttpException::class, $handler->getDontReport());
        $exceptions = $exceptions->stopIgnoring(HttpException::class);
        $this->assertInstanceOf(Exceptions::class, $exceptions);
        $this->assertNotContains(HttpException::class, $handler->getDontReport());

        $this->assertContains(ModelNotFoundException::class, $handler->getDontReport());
        $exceptions->stopIgnoring([ModelNotFoundException::class]);
        $this->assertNotContains(ModelNotFoundException::class, $handler->getDontReport());
    }

    public function testShouldRenderJsonWhen()
    {
        $exceptions = new Exceptions(new Handler(new Container));

        $shouldReturnJson = (fn () => $this->shouldReturnJson(new Request, new Exception))->call($exceptions->handler);
        $this->assertFalse($shouldReturnJson);

        $exceptions->shouldRenderJsonWhen(fn () => true);
        $shouldReturnJson = (fn () => $this->shouldReturnJson(new Request, new Exception))->call($exceptions->handler);
        $this->assertTrue($shouldReturnJson);

        $exceptions->shouldRenderJsonWhen(fn () => false);
        $shouldReturnJson = (fn () => $this->shouldReturnJson(new Request, new Exception))->call($exceptions->handler);
        $this->assertFalse($shouldReturnJson);
    }
}
