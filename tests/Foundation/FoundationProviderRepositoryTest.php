<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Exception;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\ProviderRepository;
use Hypervel\Support\ServiceProvider;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class FoundationProviderRepositoryTest extends TestCase
{
    public function testServicesAreRegisteredWhenManifestIsNotRecompiled()
    {
        $app = m::mock(Application::class);

        $repo = m::mock(ProviderRepository::class . '[createProvider,loadManifest,shouldRecompile]', [$app, m::mock(Filesystem::class), __DIR__ . '/services.php']);
        $repo->shouldReceive('loadManifest')->once()->andReturn(['eager' => ['foo'], 'deferred' => ['deferred'], 'providers' => ['providers']]);
        $repo->shouldReceive('shouldRecompile')->once()->andReturn(false);

        $app->shouldReceive('register')->once()->with('foo');
        $app->shouldReceive('addDeferredServices')->once()->with(['deferred']);

        $repo->load([]);
    }

    public function testManifestIsProperlyRecompiled()
    {
        $app = m::mock(Application::class);

        $repo = m::mock(ProviderRepository::class . '[createProvider,loadManifest,writeManifest,shouldRecompile]', [$app, m::mock(Filesystem::class), __DIR__ . '/services.php']);

        $repo->shouldReceive('loadManifest')->once()->andReturn(['eager' => [], 'deferred' => ['deferred']]);
        $repo->shouldReceive('shouldRecompile')->once()->andReturn(true);

        // foo mock is just a deferred provider
        $repo->shouldReceive('createProvider')->once()->with('foo')->andReturn($fooMock = m::mock(ServiceProvider::class));
        $fooMock->shouldReceive('isDeferred')->once()->andReturn(true);
        $fooMock->shouldReceive('provides')->once()->andReturn(['foo.provides1', 'foo.provides2']);

        // bar mock is added to eagers since it's not deferred
        $repo->shouldReceive('createProvider')->once()->with('bar')->andReturn($barMock = m::mock(ServiceProvider::class));
        $barMock->shouldReceive('isDeferred')->once()->andReturn(false);
        $repo->shouldReceive('writeManifest')->once()->andReturnUsing(function ($manifest) {
            return $manifest;
        });

        $app->shouldReceive('register')->once()->with('bar');
        $app->shouldReceive('addDeferredServices')->once()->with(['foo.provides1' => 'foo', 'foo.provides2' => 'foo']);

        $repo->load(['foo', 'bar']);
    }

    public function testShouldRecompileReturnsCorrectValue()
    {
        $repo = new ProviderRepository(m::mock(ApplicationContract::class), new Filesystem(), __DIR__ . '/services.php');
        $this->assertTrue($repo->shouldRecompile(null, []));
        $this->assertTrue($repo->shouldRecompile(['providers' => ['foo']], ['foo', 'bar']));
        $this->assertFalse($repo->shouldRecompile(['providers' => ['foo']], ['foo']));
    }

    public function testLoadManifestReturnsParsedJSON()
    {
        $repo = new ProviderRepository(m::mock(ApplicationContract::class), $files = m::mock(Filesystem::class), __DIR__ . '/services.php');
        $files->shouldReceive('exists')->once()->with(__DIR__ . '/services.php')->andReturn(true);
        $files->shouldReceive('getRequire')->once()->with(__DIR__ . '/services.php')->andReturn($array = ['users' => ['dayle' => true]]);

        $this->assertEquals($array, $repo->loadManifest());
    }

    public function testWriteManifestStoresToProperLocation()
    {
        $repo = new ProviderRepository(m::mock(ApplicationContract::class), $files = m::mock(Filesystem::class), __DIR__ . '/services.php');
        $files->shouldReceive('replace')->once()->with(__DIR__ . '/services.php', '<?php return ' . var_export(['foo'], true) . ';');

        $result = $repo->writeManifest(['foo']);

        $this->assertEquals(['foo'], $result);
    }

    public function testWriteManifestThrowsExceptionIfManifestDirDoesntExist()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/^The (.*) directory must be present and writable.$/');

        $repo = new ProviderRepository(m::mock(ApplicationContract::class), $files = m::mock(Filesystem::class), __DIR__ . '/cache/services.php');
        $files->shouldReceive('replace')->never();

        $repo->writeManifest(['foo']);
    }
}
