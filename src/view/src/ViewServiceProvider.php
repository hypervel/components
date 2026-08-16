<?php

declare(strict_types=1);

namespace Hypervel\View;

use Hypervel\Container\Container;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\ReloadsConfiguration;
use Hypervel\Support\ServiceProvider;
use Hypervel\View\Compilers\BladeCompiler;
use Hypervel\View\Compilers\Compiler;
use Hypervel\View\Engines\CompilerEngine;
use Hypervel\View\Engines\EngineResolver;
use Hypervel\View\Engines\FileEngine;
use Hypervel\View\Engines\PhpEngine;

class ViewServiceProvider extends ServiceProvider implements ReloadsConfiguration
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->registerFactory();
        $this->registerViewFinder();
        $this->registerBladeCompiler();
        $this->registerEngineResolver();
    }

    /**
     * Register the view environment.
     */
    public function registerFactory(): void
    {
        $this->app->singleton('view', function ($app) {
            // Next we need to grab the engine resolver instance that will be used by the
            // environment. The resolver will be used by an environment to get each of
            // the various engine implementations such as plain PHP or Blade engine.
            $resolver = $app->make('view.engine.resolver');

            $finder = $app->make('view.finder');

            $factory = $this->createFactory($resolver, $finder, $app->make('events'));

            // We will also set the container instance on this view environment since the
            // view composers may be classes registered in the container, which allows
            // for great testable, flexible composers for the application developer.
            $factory->setContainer($app);

            $factory->share('app', $app);

            return $factory;
        });
    }

    /**
     * Reload the worker configuration owned by the provider.
     *
     * Boot-only. Calling this while requests are running mutates shared worker
     * state while concurrent coroutines may still use the previous configuration.
     */
    public function reloadConfiguration(): void
    {
        $config = $this->app->make(ConfigRepository::class);

        if ($this->app->resolved('view')) {
            $factory = $this->app->make('view');

            if ($factory instanceof Factory && ($finder = $factory->getFinder()) instanceof FileViewFinder) {
                $finder->setPaths($config->array('view.paths'));
                $finder->flush();
            }
        }

        if ($this->app->resolved('blade.compiler')) {
            $compiler = $this->app->make('blade.compiler');

            if ($compiler instanceof Compiler) {
                $compiler->reloadConfiguration(
                    $config->string('view.compiled'),
                    $config->boolean('view.relative_hash') ? $this->app->basePath() : '',
                    $config->boolean('view.cache'),
                    $config->string('view.compiled_extension'),
                    $config->boolean('view.check_cache_timestamps'),
                );
            }
        }

        CompilerEngine::forgetCompiledOrNotExpired();
    }

    /**
     * Create a new Factory Instance.
     */
    protected function createFactory(EngineResolver $resolver, ViewFinderInterface $finder, Dispatcher $events): Factory
    {
        return new Factory($resolver, $finder, $events);
    }

    /**
     * Register the view finder implementation.
     */
    public function registerViewFinder(): void
    {
        $this->app->bind('view.finder', function ($app) {
            return new FileViewFinder($app->make('files'), $app->make('config')->array('view.paths'));
        });
    }

    /**
     * Register the Blade compiler implementation.
     */
    public function registerBladeCompiler(): void
    {
        $this->app->singleton('blade.compiler', function ($app) {
            $config = $app->make('config');

            return tap(new BladeCompiler(
                $app->make('files'),
                $config->string('view.compiled'),
                $config->boolean('view.relative_hash') ? $app->basePath() : '',
                $config->boolean('view.cache'),
                $config->string('view.compiled_extension'),
                $config->boolean('view.check_cache_timestamps'),
            ), function ($blade) {
                $blade->component('dynamic-component', DynamicComponent::class);
            });
        });
    }

    /**
     * Register the engine resolver instance.
     */
    public function registerEngineResolver(): void
    {
        $this->app->singleton('view.engine.resolver', function () {
            $resolver = new EngineResolver;

            // Next, we will register the various view engines with the resolver so that the
            // environment will resolve the engines needed for various views based on the
            // extension of view file. We call a method for each of the view's engines.
            foreach (['file', 'php', 'blade'] as $engine) {
                $this->{'register' . ucfirst($engine) . 'Engine'}($resolver);
            }

            return $resolver;
        });
    }

    /**
     * Register the file engine implementation.
     */
    public function registerFileEngine(EngineResolver $resolver): void
    {
        $resolver->register('file', function () {
            return new FileEngine(Container::getInstance()->make('files'));
        });
    }

    /**
     * Register the PHP engine implementation.
     */
    public function registerPhpEngine(EngineResolver $resolver): void
    {
        $resolver->register('php', function () {
            return new PhpEngine(Container::getInstance()->make('files'));
        });
    }

    /**
     * Register the Blade engine implementation.
     */
    public function registerBladeEngine(EngineResolver $resolver): void
    {
        $resolver->register('blade', function () {
            $app = Container::getInstance();

            return new CompilerEngine(
                $app->make('blade.compiler'),
                $app->make('files'),
            );
        });
    }
}
