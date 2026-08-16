<?php

declare(strict_types=1);

namespace Hypervel\Tests\View;

use Hypervel\Config\Repository;
use Hypervel\Events\Dispatcher;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Application;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use Hypervel\View\Compilers\BladeCompiler;
use Hypervel\View\DynamicComponent;
use Hypervel\View\Engines\CompilerEngine;
use Hypervel\View\Engines\EngineResolver;
use Hypervel\View\Factory;
use Hypervel\View\FileViewFinder;
use Hypervel\View\ViewServiceProvider;
use ReflectionMethod;

class ViewServiceProviderTest extends TestCase
{
    protected string $tempDirectory;

    protected Filesystem $filesystem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem;
        $this->tempDirectory = ParallelTesting::tempDir('ViewServiceProviderTest');
        $this->filesystem->deleteDirectory($this->tempDirectory);
        $this->filesystem->ensureDirectoryExists($this->tempDirectory);
    }

    protected function tearDown(): void
    {
        $this->filesystem->deleteDirectory($this->tempDirectory);

        parent::tearDown();
    }

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

    public function testReloadConfigurationUpdatesRetainedViewServices(): void
    {
        $oldViewPath = $this->tempDirectory . '/old-views';
        $newViewPath = $this->tempDirectory . '/new-views';
        $compiledPath = $this->tempDirectory . '/compiled';
        $this->filesystem->ensureDirectoryExists($oldViewPath);
        $this->filesystem->ensureDirectoryExists($newViewPath);
        $this->filesystem->ensureDirectoryExists($compiledPath);
        $this->filesystem->put($oldViewPath . '/page.blade.php', 'old page');
        $this->filesystem->put($newViewPath . '/page.blade.php', 'new page');
        $renderPath = $oldViewPath . '/render.blade.php';
        $this->filesystem->put($renderPath, 'before refresh');

        $application = new Application($this->tempDirectory);
        $config = new Repository([
            'view' => [
                'paths' => [$oldViewPath],
                'compiled' => $compiledPath,
                'relative_hash' => false,
                'cache' => true,
                'compiled_extension' => 'php',
                'check_cache_timestamps' => false,
            ],
        ]);
        $application->instance('config', $config);
        $application->instance('events', new Dispatcher($application));
        $application->instance('files', $this->filesystem);
        $provider = new ViewServiceProvider($application);
        $provider->register();

        $factory = $application->make('view');
        $finder = $factory->getFinder();
        $resolver = $factory->getEngineResolver();
        $compiler = $application->make('blade.compiler');
        $engine = $resolver->resolve('blade');
        $this->assertInstanceOf(FileViewFinder::class, $finder);
        $this->assertInstanceOf(EngineResolver::class, $resolver);
        $this->assertInstanceOf(BladeCompiler::class, $compiler);
        $this->assertInstanceOf(CompilerEngine::class, $engine);
        $directive = static fn (): string => 'persisted directive';
        $finder->addNamespace('package', $oldViewPath);
        $finder->addExtension('md');
        $compiler->directive('persisted_directive', $directive);
        $compiler->component('persisted-component', DynamicComponent::class);
        $compiler->if('persisted_condition', static fn (): bool => true);
        $compiler->precompiler(static fn (string $value): string => str_replace('PRECOMPILE', 'precompiled', $value));

        $this->assertSame($oldViewPath . '/page.blade.php', $finder->find('page'));
        $compiler->compile($renderPath);
        $this->assertSame('before refresh', $engine->get($renderPath));

        $this->filesystem->put($renderPath, 'after refresh');
        $config->set([
            'view.paths' => [$newViewPath],
            'view.cache' => false,
        ]);

        $provider->reloadConfiguration();

        $this->assertSame($factory, $application->make(Factory::class));
        $this->assertSame($finder, $factory->getFinder());
        $this->assertSame($resolver, $application->make('view.engine.resolver'));
        $this->assertSame($compiler, $application->make(BladeCompiler::class));
        $this->assertSame($engine, $resolver->resolve('blade'));
        $this->assertSame([$newViewPath], $finder->getPaths());
        $this->assertSame($newViewPath . '/page.blade.php', $finder->find('page'));
        $this->assertSame([$oldViewPath], $finder->getHints()['package']);
        $this->assertContains('md', $finder->getExtensions());
        $this->assertSame($directive, $compiler->getCustomDirectives()['persisted_directive']);
        $this->assertSame(DynamicComponent::class, $compiler->getClassComponentAliases()['persisted-component']);
        $this->assertTrue($compiler->check('persisted_condition'));
        $this->assertSame('precompiled', $compiler->compileString('PRECOMPILE'));
        $this->assertSame('after refresh', $engine->get($renderPath));
    }

    public function testReloadConfigurationDoesNotResolveUnusedViewServices(): void
    {
        $application = new Application($this->tempDirectory);
        $application->instance('config', new Repository([
            'view' => [
                'paths' => [$this->tempDirectory],
                'compiled' => $this->tempDirectory,
                'relative_hash' => false,
                'cache' => true,
                'compiled_extension' => 'php',
                'check_cache_timestamps' => true,
            ],
        ]));
        $application->instance('events', new Dispatcher($application));
        $application->instance('files', $this->filesystem);
        $provider = new ViewServiceProvider($application);
        $provider->register();

        $provider->reloadConfiguration();

        $this->assertFalse($application->resolved('view'));
        $this->assertFalse($application->resolved('view.finder'));
        $this->assertFalse($application->resolved('view.engine.resolver'));
        $this->assertFalse($application->resolved('blade.compiler'));
    }
}
