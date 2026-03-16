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
use Hypervel\Context\PropagatedContext;
use Hypervel\Cookie\Middleware\EncryptCookies;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Database\Console\Migrations\FreshCommand;
use Hypervel\Database\Console\Migrations\RefreshCommand;
use Hypervel\Database\Console\Migrations\ResetCommand;
use Hypervel\Database\Console\Seeds\SeedCommand;
use Hypervel\Database\Console\WipeCommand;
use Hypervel\Database\DatabaseTransactionsManager;
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
use Hypervel\Foundation\Console\AboutCommand;
use Hypervel\Foundation\Console\CliDumper;
use Hypervel\Foundation\Http\HtmlDumper;
use Hypervel\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Hypervel\Foundation\Http\Middleware\PreventRequestForgery;
use Hypervel\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Hypervel\Foundation\Http\Middleware\TrimStrings;
use Hypervel\Foundation\Support\Providers\RouteServiceProvider;
use Hypervel\Foundation\Testing\DatabaseConnectionResolver;
use Hypervel\Foundation\WorkerCachedMaintenanceMode;
use Hypervel\Horizon\SupervisorCommandString;
use Hypervel\Horizon\WorkerCommandString;
use Hypervel\Http\Middleware\HandleCors;
use Hypervel\Http\Middleware\TrustHosts;
use Hypervel\Http\Middleware\TrustProxies;
use Hypervel\Http\Resources\Json\JsonResource;
use Hypervel\Http\Resources\JsonApi\JsonApiResource;
use Hypervel\Pagination\AbstractCursorPaginator;
use Hypervel\Pagination\AbstractPaginator;
use Hypervel\Queue\Console\WorkCommand;
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
use Hypervel\Server\ServerManager;
use Hypervel\ServerProcess\ProcessCollector;
use Hypervel\ServerProcess\ProcessManager;
use Hypervel\Session\Middleware\AuthenticateSession;
use Hypervel\Session\Store;
use Hypervel\Support\BinaryCodec;
use Hypervel\Support\Composer;
use Hypervel\Support\DateFactory;
use Hypervel\Support\DotenvManager;
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
use Hypervel\View\Engines\CompilerEngine;
use Mockery;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;

/**
 * Global cleanup after every test method.
 *
 * Runs after tearDown() completes, for ALL tests regardless of base class.
 * Centralizes static state resets so individual tests don't need to remember
 * them, and prevents state leaks even when a test forgets to clean up.
 */
final class AfterEachTestSubscriber implements FinishedSubscriber
{
    public function notify(Finished $event): void
    {
        if (class_exists(Mockery::class)) {
            Mockery::close();
        }

        AboutCommand::flushState();
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
        CallableDispatcher::flushState();
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
        ControllerDispatcher::flushState();
        ConvertEmptyStringsToNull::flushState();
        Coroutine::flushAfterCreated();
        DatabaseConnectionResolver::flushCachedConnections();
        DatabaseTransactionsManager::flushState();
        DateFactory::useDefault();
        DotenvManager::flushState();
        EncryptCookies::flushState();
        Facade::clearResolvedInstances();
        Factory::flushState();
        FreshCommand::prohibit(false);
        HandleCors::flushState();
        HtmlDumper::resolveDumpSourceUsing(null);
        ImplicitRouteBinding::flushCache();
        JsonApiResource::flushState();
        JsonResource::flushState();
        Lottery::determineResultNormally();
        Model::clearBootedModels();
        Model::flushCasterCache();
        Model::preventAccessingMissingAttributes(false);
        Model::preventLazyLoading(false);
        Model::preventSilentlyDiscardingAttributes(false);
        Model::unsetConnectionResolver();
        Model::unsetEventDispatcher();
        Number::flushState();
        Once::flushState();
        PreventRequestForgery::flushState();
        PreventRequestsDuringMaintenance::flushState();
        ProcessCollector::flushState();
        ProcessManager::flushState();
        PropagatedContext::flushState();
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
        ServerManager::flushState();
        ServiceProvider::flushState();
        Sleep::fake(false);
        SortedMiddleware::flushCache();
        Store::flushState();
        Str::resetFactoryState();
        SupervisorCommandString::flushState();
        StrCache::flushState();
        Telescope::flushState();
        TrimStrings::flushState();
        TrustHosts::flushState();
        TrustProxies::flushState();
        UrlGenerator::flushRequestState();
        ValidateSignature::flushState();
        Validator::flushState();
        CompilerEngine::forgetCompiledOrNotExpired();
        Component::flushCache();
        Component::forgetComponentsResolver();
        Component::forgetFactory();
        WipeCommand::prohibit(false);
        WorkCommand::flushState();
        WorkerCachedMaintenanceMode::flushCache();
        WorkerCommandString::flushState();
    }
}
