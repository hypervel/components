<?php

declare(strict_types=1);

namespace Hypervel\Tests\Container;

use Hypervel\Container\Container;
use Hypervel\Tests\TestCase;
use RuntimeException;
use stdClass;

class ResolvingCallbackNonCoroutineTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testCallbackFailureRollsBackSingletonWithoutCoordinator(): void
    {
        $container = new Container;
        $constructions = 0;
        $shouldFail = true;

        $container->singleton('service', function () use (&$constructions) {
            $service = new stdClass;
            $service->construction = ++$constructions;

            return $service;
        });
        $container->resolving('service', function () use (&$shouldFail): void {
            if ($shouldFail) {
                $shouldFail = false;

                throw new RuntimeException('callback failed');
            }
        });

        try {
            $container->make('service');
            $this->fail('Expected the resolving callback to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('callback failed', $exception->getMessage());
        }

        $resolved = $container->make('service');

        $this->assertSame(2, $resolved->construction);
        $this->assertSame($resolved, $container->make('service'));
    }
}
