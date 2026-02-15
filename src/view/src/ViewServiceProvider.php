<?php

declare(strict_types=1);

namespace Hypervel\View;

use Hypervel\Container\Container;
use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Support\ServiceProvider;
use Hypervel\View\Compilers\BladeCompiler;
use Hypervel\View\Compilers\CompilerInterface;
use Hypervel\View\Contracts\Factory as FactoryContract;
use Hypervel\View\Engines\CompilerEngine;
use Hypervel\View\Engines\EngineResolver;
use Hypervel\View\Engines\FileEngine;
use Hypervel\View\Engines\PhpEngine;

class ViewServiceProvider extends ServiceProvider
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
    protected function registerFactory(): void
    {
        $this->app->bind(FactoryContract::class, function ($app) {
            // Next we need to grab the engine resolver instance that will be used by the
            // environment. The resolver will be used by an environment to get each of
            // the various engine implementations such as plain PHP or Blade engine.
            $resolver = $app->get(EngineResolver::class);

            $finder = $app->get(ViewFinderInterface::class);

            $factory = $this->createFactory($resolver, $finder, $app['events']);

            // We will also set the container instance on this view environment since the
            // view composers may be classes registered in the container, which allows
            // for great testable, flexible composers for the application developer.
            $factory->setContainer($app);

            $factory->share('app', $app);

            return $factory;
        });
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
    protected function registerViewFinder(): void
    {
        $this->app->bind(ViewFinderInterface::class, function ($app) {
            return new FileViewFinder($app['files'], $app['config']['view.paths']);
        });
    }

    /**
     * Register the Blade compiler implementation.
     */
    protected function registerBladeCompiler(): void
    {
        $this->app->bind(CompilerInterface::class, function ($app) {
            return tap(new BladeCompiler(
                $app['files'],
                $app['config']['view.compiled'],
                $app['config']->get('view.relative_hash', false) ? $app->basePath() : '',
                $app['config']->get('view.cache', true),
                $app['config']->get('view.compiled_extension', 'php'),
            ), function ($blade) {
                $blade->component('dynamic-component', DynamicComponent::class);
            });
        });
    }

    /**
     * Register the engine resolver instance.
     */
    protected function registerEngineResolver(): void
    {
        $this->app->bind(EngineResolver::class, function () {
            $resolver = new EngineResolver();

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
    protected function registerFileEngine(EngineResolver $resolver): void
    {
        $resolver->register('file', function () {
            return new FileEngine(Container::getInstance()->get('files'));
        });
    }

    /**
     * Register the PHP engine implementation.
     */
    protected function registerPhpEngine(EngineResolver $resolver): void
    {
        $resolver->register('php', function () {
            return new PhpEngine(Container::getInstance()->get('files'));
        });
    }

    /**
     * Register the Blade engine implementation.
     */
    protected function registerBladeEngine(EngineResolver $resolver): void
    {
        $resolver->register('blade', function () {
            $app = Container::getInstance();

            return new CompilerEngine(
                $app->get(CompilerInterface::class),
                $app->get('files'),
            );
        });
    }
}
