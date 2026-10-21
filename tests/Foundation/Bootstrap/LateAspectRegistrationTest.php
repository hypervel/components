<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Bootstrap;

use Hypervel\Di\Exceptions\InvalidDefinitionException;
use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\Foundation\Application;
use Hypervel\Tests\Di\Fixtures\Aspect\TestAspect;
use Hypervel\Tests\TestCase;
use RuntimeException;

use function Hypervel\Testbench\default_skeleton_path;

class LateAspectRegistrationTest extends TestCase
{
    public function testProviderBootCannotRegisterAspects(): void
    {
        $this->expectException(InvalidDefinitionException::class);
        $this->expectExceptionMessageIsOrContains('Register aspects in a service provider\'s register()');

        Application::create(
            (string) default_skeleton_path(),
            options: ['extra' => ['providers' => [LateAspectRegistrationProvider::class]]],
        );
    }

    public function testProviderBootCannotRegisterClassMapOverrides(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Register overrides in a service provider\'s register()');

        Application::create(
            (string) default_skeleton_path(),
            options: ['extra' => ['providers' => [LateClassMapRegistrationProvider::class]]],
        );
    }
}

class LateAspectRegistrationProvider extends ServiceProvider
{
    /**
     * Attempt to register an aspect during provider boot.
     */
    public function boot(): void
    {
        $this->aspects(TestAspect::class);
    }
}

class LateClassMapRegistrationProvider extends ServiceProvider
{
    /**
     * Attempt to register a class map during provider boot.
     */
    public function boot(): void
    {
        $this->classMap([self::class => __FILE__]);
    }
}
