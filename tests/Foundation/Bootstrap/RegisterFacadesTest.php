<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Bootstrap;

use Hypervel\Config\Repository;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Bootstrap\RegisterFacades;
use Hypervel\Foundation\PackageManifest;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class RegisterFacadesTest extends TestCase
{
    public function testRegisterAliases()
    {
        $config = m::mock(Repository::class);
        $config->shouldReceive('get')
            ->with('app.aliases', [])
            ->once()
            ->andReturn([
                'FooAlias' => 'FooClass',
            ]);

        $manifest = m::mock(PackageManifest::class);
        $manifest->shouldReceive('aliases')
            ->once()
            ->andReturn([
                'TestAlias' => 'TestClass',
            ]);

        $app = new Application();
        $app->singleton('config', fn () => $config);
        $app->singleton(PackageManifest::class, fn () => $manifest);

        $bootstrapper = $this->createPartialMock(
            RegisterFacades::class,
            ['registerAliases']
        );

        // Package aliases come first, then config aliases override
        $bootstrapper->expects($this->once())
            ->method('registerAliases')
            ->with([
                'TestAlias' => 'TestClass',
                'FooAlias' => 'FooClass',
            ]);

        $bootstrapper->bootstrap($app);
    }
}
