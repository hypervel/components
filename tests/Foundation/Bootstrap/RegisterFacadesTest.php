<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Bootstrap;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Config\Repository as ConfigContract;
use Hypervel\Foundation\Bootstrap\RegisterFacades;
use Hypervel\Support\Composer;
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

        $app = $this->getApplication([
            ConfigContract::class => fn () => $config,
        ]);

        $bootstrapper = $this->createPartialMock(
            RegisterFacades::class,
            ['registerAliases']
        );

        $bootstrapper->expects($this->once())
            ->method('registerAliases')
            ->with([
                'FooAlias' => 'FooClass',
                'TestAlias' => 'TestClass',
            ]);

        Composer::setBasePath(dirname(__DIR__) . '/fixtures/project1');

        $bootstrapper->bootstrap($app);
    }
}
