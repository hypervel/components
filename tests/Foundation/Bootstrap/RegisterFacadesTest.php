<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Bootstrap;

use Hypervel\Config\Repository;
use Hypervel\Foundation\Bootstrap\RegisterFacades;
use Hypervel\Foundation\PackageManifest;
use Hypervel\Tests\Foundation\Concerns\HasMockedApplication;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class RegisterFacadesTest extends TestCase
{
    use HasMockedApplication;

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

        $app = $this->getApplication([
            'config' => fn () => $config,
            PackageManifest::class => fn () => $manifest,
        ]);

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
