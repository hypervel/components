<?php

declare(strict_types=1);

namespace Hypervel\Tests\Workbench;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Vite;
use Hypervel\Testbench\Concerns\WithWorkbench;
use Hypervel\Testbench\Contracts\Config as ConfigContract;
use Hypervel\Testbench\TestCase;
use Hypervel\Workbench\Workbench;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Providers\WorkbenchServiceProvider;

use function Hypervel\Testbench\package_path;

class WorkbenchTest extends TestCase
{
    use WithWorkbench;

    // REMOVED: it_can_resolve_stub_files() covers the excluded Canvas stub registrar.

    #[Test]
    public function itCanResolveWithWorkbenchTraits(): void
    {
        $this->assertTrue($this->app->providerIsLoaded(WorkbenchServiceProvider::class));
    }

    #[Test]
    public function itCanResolveHypervelPath(): void
    {
        $this->assertSame(base_path(), Workbench::applicationPath());
        $this->assertSame(base_path(), Workbench::hypervelPath());

        $this->assertSame(base_path('artisan'), Workbench::applicationPath('artisan'));
        $this->assertSame(base_path('artisan'), Workbench::hypervelPath('artisan'));

        $this->assertSame(base_path('resources/views'), Workbench::applicationPath('resources', 'views'));
        $this->assertSame(base_path('resources/views'), Workbench::hypervelPath('resources', 'views'));

        $this->assertSame(base_path('resources/views'), Workbench::applicationPath(['resources', 'views']));
        $this->assertSame(base_path('resources/views'), Workbench::hypervelPath(['resources', 'views']));
    }

    #[Test]
    public function itCanResolvePackagePath(): void
    {
        $this->assertSame(
            realpath(__DIR__ . '/../..'),
            rtrim(Workbench::packagePath(), DIRECTORY_SEPARATOR)
        );

        $this->assertSame(
            realpath(__DIR__ . '/../../composer.json'),
            Workbench::packagePath('composer.json')
        );

        $this->assertSame(
            realpath(__FILE__),
            Workbench::packagePath('tests', 'Workbench', 'WorkbenchTest.php')
        );

        $this->assertSame(
            realpath(__FILE__),
            Workbench::packagePath(['tests', 'Workbench', 'WorkbenchTest.php'])
        );
    }

    #[Test]
    public function itCanResolveWorkbenchPath(): void
    {
        $workbenchPath = package_path('src', 'testbench', 'workbench');

        $this->assertSame(
            realpath($workbenchPath),
            rtrim(Workbench::path(), DIRECTORY_SEPARATOR)
        );

        $this->assertSame(
            realpath($workbenchPath . '/routes/web.php'),
            Workbench::path('routes' . DIRECTORY_SEPARATOR . 'web.php')
        );

        $this->assertSame(
            realpath($workbenchPath . '/routes/web.php'),
            Workbench::path('routes', 'web.php')
        );

        $this->assertSame(
            realpath($workbenchPath . '/routes/web.php'),
            Workbench::path(['routes', 'web.php'])
        );
    }

    #[Test]
    public function itCanResolveWorkbenchConfig(): void
    {
        $config = app(ConfigContract::class)->getWorkbenchAttributes();

        $this->assertSame(
            $config,
            Workbench::config()
        );

        $this->assertSame(
            $config['start'],
            Workbench::config('start')
        );

        $this->assertSame([
            'config' => true,
            'factories' => true,
            'web' => true,
            'api' => true,
            'commands' => true,
            'components' => false,
            'views' => true,
        ], Workbench::config('discovers'));
    }

    #[Test]
    public function itRendersViteTagsFromTheWorkbenchBuildWithoutChangingTheApplicationVite(): void
    {
        $filesystem = new Filesystem;
        $buildPath = public_path('vendor/workbench/build');
        $hotFile = public_path('hot');

        $vite = $this->app->make(Vite::class)
            ->useHotFile($hotFile)
            ->useManifestFilename('application-manifest.json')
            ->useScriptTagAttributes(['data-preview' => 'workbench']);

        $filesystem->copyDirectory(package_path('src', 'workbench', 'public', 'build'), $buildPath);
        $filesystem->put($hotFile, 'http://127.0.0.1:5173');

        try {
            $html = Workbench::vite(['resources/css/app.css', 'resources/js/app.js'])->toHtml();
        } finally {
            $filesystem->deleteDirectory(public_path('vendor/workbench'));
            $filesystem->delete($hotFile);
        }

        $this->assertMatchesRegularExpression('#href="http://localhost/vendor/workbench/build/assets/app-[^"]+\.css"#', $html);
        $this->assertMatchesRegularExpression('#src="http://localhost/vendor/workbench/build/assets/app-[^"]+\.js" data-preview="workbench"#', $html);
        $this->assertStringNotContainsString('127.0.0.1:5173', $html);
        $this->assertSame($hotFile, $vite->hotFile());
    }
}
