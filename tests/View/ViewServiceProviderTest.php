<?php

declare(strict_types=1);

namespace Hypervel\Tests\View;

use Hypervel\Tests\TestCase;
use Hypervel\View\ViewServiceProvider;
use ReflectionMethod;

class ViewServiceProviderTest extends TestCase
{
    public function testEngineAndViewRegistrationMethodsArePublic(): void
    {
        foreach ([
            'registerFactory',
            'registerViewFinder',
            'registerBladeCompiler',
            'registerEngineResolver',
            'registerFileEngine',
            'registerPhpEngine',
            'registerBladeEngine',
        ] as $method) {
            $this->assertTrue((new ReflectionMethod(ViewServiceProvider::class, $method))->isPublic());
        }

        $this->assertTrue((new ReflectionMethod(ViewServiceProvider::class, 'createFactory'))->isProtected());
    }
}
