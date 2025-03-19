<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Bootstrap;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Foundation\Bootstrap\RegisterFacades;
use Hypervel\Foundation\Support\Composer;
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
        $config = m::mock(ConfigInterface::class);
        $config->shouldReceive('get')
            ->with('app.aliases', [])
            ->once()
            ->andReturn([
                'FooAlias' => 'FooClass',
            ]);

        $app = $this->getApplication([
            ConfigInterface::class => fn () => $config,
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

        Composer::setBasePath(dirname(__DIR__) . '/fixtures/hyperf1');

        $bootstrapper->bootstrap($app);
    }
}
