<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Bootstrap;

use Hypervel\Config\Repository;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Bootstrap\RegisterFacades;
use Hypervel\Foundation\PackageManifest;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class RegisterFacadesTest extends TestCase
{
    use VerifiesDoubles;

    // REMOVED: Laravel's AliasLoader real-time facade path; Hypervel supports
    // only explicit facade aliases.
    public function testRegisterAliases(): void
    {
        $config = Double::for(Repository::class);
        $config->expects('array')
            ->with('app.aliases')
            ->returns([
                'FooAlias' => 'FooClass',
            ]);

        $manifest = Double::for(PackageManifest::class);
        $manifest->expects('aliases')
            ->returns([
                'TestAlias' => 'TestClass',
            ]);

        $app = new Application;
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
