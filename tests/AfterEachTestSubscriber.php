<?php

declare(strict_types=1);

namespace Hypervel\Tests;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Hypervel\Broadcasting\Broadcasters\Broadcaster;
use Hypervel\Console\Application as ConsoleApplication;
use Hypervel\Container\BoundMethod;
use Hypervel\Container\Container;
use Hypervel\Context\Context;
use Hypervel\Cookie\Middleware\EncryptCookies;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Database\Console\Migrations\FreshCommand;
use Hypervel\Database\Console\Migrations\RefreshCommand;
use Hypervel\Database\Console\Migrations\ResetCommand;
use Hypervel\Database\Console\Seeds\SeedCommand;
use Hypervel\Database\Console\WipeCommand;
use Hypervel\Database\Eloquent\Factories\Factory;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Database\Schema\Builder as SchemaBuilder;
use Hypervel\Di\Aop\AspectCollector;
use Hypervel\Di\Aop\AspectManager;
use Hypervel\Di\Aop\AstVisitorRegistry;
use Hypervel\Di\ClassMap\ClassMapManager;
use Hypervel\Di\ReflectionManager;
use Hypervel\Foundation\Bootstrap\RegisterProviders;
use Hypervel\Foundation\Console\CliDumper;
use Hypervel\Foundation\Http\HtmlDumper;
use Hypervel\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Hypervel\Foundation\Http\Middleware\PreventRequestForgery;
use Hypervel\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Hypervel\Foundation\Http\Middleware\TrimStrings;
use Hypervel\Foundation\Support\Providers\RouteServiceProvider;
use Hypervel\Foundation\WorkerCachedMaintenanceMode;
use Hypervel\Http\Middleware\HandleCors;
use Hypervel\Http\Middleware\TrustHosts;
use Hypervel\Http\Middleware\TrustProxies;
use Hypervel\Pagination\AbstractCursorPaginator;
use Hypervel\Pagination\AbstractPaginator;
use Hypervel\Queue\Queue;
use Hypervel\Routing\CallableDispatcher;
use Hypervel\Routing\CompiledRouteCollection;
use Hypervel\Routing\ControllerDispatcher;
use Hypervel\Routing\ImplicitRouteBinding;
use Hypervel\Routing\ResourceRegistrar;
use Hypervel\Routing\RouteSignatureParameters;
use Hypervel\Routing\SortedMiddleware;
use Hypervel\Routing\UrlGenerator;
use Hypervel\Sanctum\Sanctum;
use Hypervel\Scout\Scout;
use Hypervel\ServerProcess\ProcessManager;
use Hypervel\Session\Store;
use Hypervel\Support\Composer;
use Hypervel\Support\DateFactory;
use Hypervel\Support\Facades\Facade;
use Hypervel\Support\Lottery;
use Hypervel\Support\Number;
use Hypervel\Support\Once;
use Hypervel\Support\ServiceProvider;
use Hypervel\Support\Sleep;
use Hypervel\Support\Str;
use Hypervel\Support\StrCache;
use Hypervel\Telescope\Telescope;
use Hypervel\Validation\Validator;
use Hypervel\View\Component;
use Mockery;
use PHPUnit\Event\Test\AfterTestMethodFinished;
use PHPUnit\Event\Test\AfterTestMethodFinishedSubscriber;
use Ramsey\Uuid\UuidFactory;

/**
 * Global cleanup after every test method.
 *
 * Runs after tearDown() completes, for ALL tests regardless of base class.
 * Centralizes static state resets so individual tests don't need to remember
 * them, and prevents state leaks even when a test forgets to clean up.
 */
final class AfterEachTestSubscriber implements AfterTestMethodFinishedSubscriber
{
    public function notify(AfterTestMethodFinished $event): void
    {
        // Mockery
        if (class_exists(Mockery::class)) {
            Mockery::close();
        }

        // Time, randomness, and faking
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        Carbon::resetToStringFormat();
        Carbon::serializeUsing(null);
        Sleep::fake(false);
        Str::resetFactoryState();
        Lottery::determineResultNormally();
        DateFactory::useDefault();
        if (class_exists(\Ramsey\Uuid\Uuid::class)) {
            \Ramsey\Uuid\Uuid::setFactory(new UuidFactory());
        }

        // Context (coroutine and non-coroutine)
        Context::flush();

        // Container and application singletons
        Container::setInstance(null);
        Facade::clearResolvedInstances();

        // Eloquent model state
        Model::unsetEventDispatcher();
        Model::unsetConnectionResolver();
        Model::preventSilentlyDiscardingAttributes(false);
        Model::preventLazyLoading(false);
        Model::preventAccessingMissingAttributes(false);
        Relation::morphMap([], false);
        Relation::requireMorphMap(false);
        Factory::flushState();

        // Database
        SchemaBuilder::flushState();

        // Queue
        Queue::createPayloadUsing(null);

        // Routing
        UrlGenerator::flushRequestState();
        AbstractPaginator::currentPathResolver(fn () => '/');
        AbstractPaginator::currentPageResolver(fn () => 1);
        AbstractCursorPaginator::currentCursorResolver(fn () => null);
        ResourceRegistrar::verbs(['create' => 'create', 'edit' => 'edit']);
        ResourceRegistrar::setParameters();
        ResourceRegistrar::singularParameters();
        ImplicitRouteBinding::flushCache();
        CallableDispatcher::flushCache();
        ControllerDispatcher::flushCache();
        RouteSignatureParameters::flushCache();
        SortedMiddleware::flushCache();
        CompiledRouteCollection::flushCache();

        // Middleware and bootstrapper static state
        EncryptCookies::flushState();
        ConvertEmptyStringsToNull::flushState();
        PreventRequestForgery::flushState();
        PreventRequestsDuringMaintenance::flushState();
        TrimStrings::flushState();
        TrustProxies::flushState();
        HandleCors::flushState();
        TrustHosts::flushState();
        RouteServiceProvider::flushState();
        RegisterProviders::flushState();
        WorkerCachedMaintenanceMode::flushCache();

        // Session
        Store::flushState();

        // View
        Component::flushCache();
        Component::forgetFactory();
        Component::forgetComponentsResolver();

        // Broadcasting
        Broadcaster::flushChannels();

        // Support utilities
        BoundMethod::flushMethodRecipeCache();
        Once::flush();
        Once::enable();
        StrCache::flushState();
        Number::flushState();
        Composer::setBasePath(null);

        // Validation
        Validator::flushState();

        // Console
        ConsoleApplication::forgetBootstrappers();
        FreshCommand::prohibit(false);
        RefreshCommand::prohibit(false);
        ResetCommand::prohibit(false);
        WipeCommand::prohibit(false);
        SeedCommand::prohibit(false);

        // Service provider publish state
        ServiceProvider::flushState();

        // Coroutine
        Coroutine::flushAfterCreated();

        // Dumpers
        CliDumper::resolveDumpSourceUsing(null);
        HtmlDumper::resolveDumpSourceUsing(null);

        // DI / AOP
        AspectCollector::flushState();
        AspectManager::flushState();
        AstVisitorRegistry::flushState();
        ClassMapManager::flushState();
        ReflectionManager::flushState();

        // Server processes
        ProcessManager::flushState();

        // Sanctum
        Sanctum::flushState();

        // Scout
        Scout::flushState();

        // Telescope
        Telescope::$filterUsing = [];
        Telescope::$filterBatchUsing = [];
        Telescope::$afterRecordingHook = null;
        Telescope::flushWatchers();
        Telescope::auth(null);
    }
}
