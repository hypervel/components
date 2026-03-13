<?php

declare(strict_types=1);

namespace Hypervel\Tests;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Hypervel\ApiClient\PendingRequest as ApiClientPendingRequest;
use Hypervel\Auth\AuthenticationException;
use Hypervel\Auth\Middleware\Authenticate;
use Hypervel\Auth\Middleware\RedirectIfAuthenticated;
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
use Hypervel\Routing\Middleware\ValidateSignature;
use Hypervel\Routing\ResourceRegistrar;
use Hypervel\Routing\RouteSignatureParameters;
use Hypervel\Routing\SortedMiddleware;
use Hypervel\Routing\UrlGenerator;
use Hypervel\Sanctum\Sanctum;
use Hypervel\Scout\Scout;
use Hypervel\ServerProcess\ProcessCollector;
use Hypervel\ServerProcess\ProcessManager;
use Hypervel\Session\Middleware\AuthenticateSession;
use Hypervel\Session\Store;
use Hypervel\Support\BinaryCodec;
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
        if (class_exists(Mockery::class)) {
            Mockery::close();
        }

        AbstractCursorPaginator::flushState();
        AbstractPaginator::flushState();
        ApiClientPendingRequest::flushCache();
        AspectCollector::flushState();
        AspectManager::flushState();
        AstVisitorRegistry::flushState();
        Authenticate::flushState();
        AuthenticationException::flushState();
        AuthenticateSession::flushState();
        BinaryCodec::flushState();
        BoundMethod::flushMethodRecipeCache();
        Broadcaster::flushChannels();
        CallableDispatcher::flushCache();
        Carbon::resetToStringFormat();
        Carbon::serializeUsing(null);
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        ClassMapManager::flushState();
        CliDumper::resolveDumpSourceUsing(null);
        CompiledRouteCollection::flushCache();
        Composer::flushState();
        ConsoleApplication::forgetBootstrappers();
        Container::setInstance(null);
        Context::flush();
        ControllerDispatcher::flushCache();
        ConvertEmptyStringsToNull::flushState();
        Coroutine::flushAfterCreated();
        DateFactory::useDefault();
        EncryptCookies::flushState();
        Facade::clearResolvedInstances();
        Factory::flushState();
        FreshCommand::prohibit(false);
        HandleCors::flushState();
        HtmlDumper::resolveDumpSourceUsing(null);
        ImplicitRouteBinding::flushCache();
        Lottery::determineResultNormally();
        Model::preventAccessingMissingAttributes(false);
        Model::preventLazyLoading(false);
        Model::preventSilentlyDiscardingAttributes(false);
        Model::unsetConnectionResolver();
        Model::unsetEventDispatcher();
        Number::flushState();
        Once::enable();
        Once::flush();
        PreventRequestForgery::flushState();
        PreventRequestsDuringMaintenance::flushState();
        ProcessCollector::flushState();
        ProcessManager::flushState();
        Queue::createPayloadUsing(null);
        RedirectIfAuthenticated::flushState();
        ReflectionManager::flushState();
        RefreshCommand::prohibit(false);
        RegisterProviders::flushState();
        Relation::morphMap([], false);
        Relation::requireMorphMap(false);
        ResetCommand::prohibit(false);
        ResourceRegistrar::flushState();
        RouteServiceProvider::flushState();
        RouteSignatureParameters::flushCache();
        Sanctum::flushState();
        SchemaBuilder::flushState();
        Scout::flushState();
        SeedCommand::prohibit(false);
        ServiceProvider::flushState();
        Sleep::fake(false);
        SortedMiddleware::flushCache();
        Store::flushState();
        Str::resetFactoryState();
        StrCache::flushState();
        Telescope::flushState();
        TrimStrings::flushState();
        TrustHosts::flushState();
        TrustProxies::flushState();
        UrlGenerator::flushRequestState();
        ValidateSignature::flushState();
        Validator::flushState();
        Component::flushCache();
        Component::forgetComponentsResolver();
        Component::forgetFactory();
        WipeCommand::prohibit(false);
        WorkerCachedMaintenanceMode::flushCache();

        if (class_exists(\Ramsey\Uuid\Uuid::class)) {
            \Ramsey\Uuid\Uuid::setFactory(new UuidFactory());
        }
    }
}
