<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Attributes\FromContainerTest;

use Hypervel\Container\Attributes\Give;
use Hypervel\Contracts\Container\BindingResolutionException;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Data;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Testbench\TestCase;

class FromContainerTest extends TestCase
{
    // Spatie's FromContainer is not included; Hypervel's Give contextual attribute resolves a container entry.

    /**
     * Get package providers for the test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    public function testCanGetTheContainer(): void
    {
        $dataClass = new class($this->app) extends Data {
            /**
             * Create the data object from the container.
             */
            public function __construct(
                #[Give(Container::class)]
                public Container $container,
            ) {
            }
        };

        $this->assertSame($this->app, $dataClass::from()->container);
    }

    public function testCanGetADependencyFromTheContainer(): void
    {
        $this->app->bind('test', fn (): string => 'test');

        $dataClass = new class('') extends Data {
            /**
             * Create the data object from a container entry.
             */
            public function __construct(
                #[Give('test')]
                public string $test,
            ) {
            }
        };

        $this->assertSame('test', $dataClass::from()->test);
    }

    public function testCanGetADependencyFromTheContainerWithParameters(): void
    {
        $this->app->bind('test', fn (Container $app, array $parameters): string => $parameters['parameter']);

        $dataClass = new class('') extends Data {
            /**
             * Create the data object from a container entry built with parameters.
             */
            public function __construct(
                #[Give('test', ['parameter' => 'Hello World'])]
                public string $test,
            ) {
            }
        };

        $this->assertSame('Hello World', $dataClass::from()->test);
    }

    public function testWillNotSetAPropertyWhenTheDependencyIsNotFound(): void
    {
        $dataClass = new class('') extends Data {
            /**
             * Create the data object from a missing container entry.
             */
            public function __construct(
                #[Give('test')]
                public string $test,
            ) {
            }
        };

        // Upstream leaves the property unset; the container's resolution error is not hidden here.
        $this->expectException(BindingResolutionException::class);

        $dataClass::from();
    }
}
