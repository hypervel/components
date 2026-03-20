<?php

declare(strict_types=1);

namespace Hypervel\Tests;

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

        \Hypervel\Foundation\Console\AboutCommand::flushState();
        \Hypervel\Pagination\AbstractCursorPaginator::flushState();
        \Hypervel\Pagination\AbstractPaginator::flushState();
        \Hypervel\ApiClient\PendingRequest::flushCache();
        \Hypervel\Support\Arr::flushState();
        \Hypervel\Di\Aop\AspectCollector::flushState();
        \Hypervel\Di\Aop\AspectManager::flushState();
        \Hypervel\Testing\Fluent\AssertableJson::flushState();
        \Hypervel\Di\Aop\AstVisitorRegistry::flushState();
        \Hypervel\Mail\Attachment::flushState();
        \Hypervel\Auth\Middleware\Authenticate::flushState();
        \Hypervel\Auth\AuthenticationException::flushState();
        \Hypervel\Session\Middleware\AuthenticateSession::flushState();
        \Hypervel\Support\BinaryCodec::flushState();
        \Hypervel\Database\Schema\Blueprint::flushState();
        \Hypervel\Container\BoundMethod::flushMethodRecipeCache();
        \Hypervel\Broadcasting\Broadcasters\Broadcaster::flushChannels();
        \Hypervel\Cache\Repository::flushState();
        \Hypervel\Routing\CallableDispatcher::flushState();
        \Carbon\Carbon::resetMacros();
        \Carbon\Carbon::resetToStringFormat();
        \Carbon\Carbon::serializeUsing(null);
        \Carbon\Carbon::setTestNow();
        \Carbon\CarbonImmutable::setTestNow();
        \Hypervel\Di\ClassMap\ClassMapManager::flushState();
        \Hypervel\Foundation\Console\CliDumper::resolveDumpSourceUsing(null);
        \Hypervel\Support\Collection::flushState();
        \Hypervel\View\Engines\CompilerEngine::forgetCompiledOrNotExpired();
        \Hypervel\Routing\CompiledRouteCollection::flushCache();
        \Hypervel\View\Component::flushCache();
        \Hypervel\View\Component::forgetComponentsResolver();
        \Hypervel\View\Component::forgetFactory();
        \Hypervel\Support\Composer::flushState();
        \Hypervel\Console\Application::forgetBootstrappers();
        \Hypervel\Foundation\PackageManifest::flushState();
        \Hypervel\Container\Container::setInstance(null);
        \Hypervel\Context\Context::flush();
        \Hypervel\Routing\ControllerDispatcher::flushState();
        \Hypervel\Foundation\Http\Middleware\ConvertEmptyStringsToNull::flushState();
        \Hypervel\Coroutine\Coroutine::flushAfterCreated();
        \Hypervel\Foundation\Testing\DatabaseConnectionResolver::flushCachedConnections();
        \Hypervel\Database\DatabaseManager::purgeConnections();
        \Hypervel\Database\DatabaseTransactionsManager::flushState();
        \Hypervel\Support\DateFactory::useDefault();
        \Hypervel\Validation\Rules\Date::flushState();
        \Hypervel\Foundation\Events\DiscoverEvents::flushState();
        \Hypervel\Support\DotenvManager::flushState();
        \Hypervel\Database\Eloquent\Builder::flushState();
        \Hypervel\Validation\Rules\Email::flushState();
        \Hypervel\Support\EncodedHtmlString::flushState();
        \Hypervel\Cookie\Middleware\EncryptCookies::flushState();
        \Hypervel\Foundation\Support\Providers\EventServiceProvider::flushState();
        \Hypervel\Support\Facades\Facade::clearResolvedInstances();
        \Hypervel\Database\Eloquent\Factories\Factory::flushState();
        \Hypervel\Validation\Rules\File::flushState();
        \Hypervel\Support\Fluent::flushState();
        \Hypervel\Foundation\Http\FormRequest::flushState();
        \Hypervel\Database\Console\Migrations\FreshCommand::prohibit(false);
        \Hypervel\Database\Grammar::flushState();
        \Hypervel\Http\Middleware\HandleCors::flushState();
        \Hypervel\Foundation\Http\HtmlDumper::resolveDumpSourceUsing(null);
        \Hypervel\Http\Client\Request::flushState();
        \Hypervel\Routing\ImplicitRouteBinding::flushCache();
        \Hypervel\Http\Resources\JsonApi\JsonApiResource::flushState();
        \Hypervel\Http\Resources\Json\JsonResource::flushState();
        \Hypervel\Support\Lottery::determineResultNormally();
        \Hypervel\Mail\Mailer::flushState();
        \Hypervel\Mail\Markdown::flushState();
        \Hypervel\Database\Eloquent\Model::clearBootedModels();
        \Hypervel\Database\Eloquent\Model::flushCasterCache();
        \Hypervel\Database\Eloquent\Model::preventAccessingMissingAttributes(false);
        \Hypervel\Database\Eloquent\Model::preventLazyLoading(false);
        \Hypervel\Database\Eloquent\Model::preventSilentlyDiscardingAttributes(false);
        \Hypervel\Database\Eloquent\Model::unsetConnectionResolver();
        \Hypervel\Database\Eloquent\Model::unsetEventDispatcher();
        \Hypervel\Support\Number::flushState();
        \Hypervel\Support\Once::flushState();
        \Hypervel\Pipeline\Pipeline::flushState();
        \Hypervel\Foundation\Http\Middleware\PreventRequestForgery::flushState();
        \Hypervel\Foundation\Http\Middleware\PreventRequestsDuringMaintenance::flushState();
        \Hypervel\ServerProcess\ProcessCollector::flushState();
        \Hypervel\ServerProcess\ProcessManager::flushState();
        \Hypervel\Prompts\Prompt::flushState();
        \Hypervel\Context\PropagatedContext::flushState();
        \Hypervel\Queue\Queue::createPayloadUsing(null);
        \Hypervel\Auth\Middleware\RedirectIfAuthenticated::flushState();
        \Hypervel\Di\ReflectionManager::flushState();
        \Hypervel\Database\Console\Migrations\RefreshCommand::prohibit(false);
        \Hypervel\Foundation\Bootstrap\RegisterProviders::flushState();
        \Hypervel\Database\Eloquent\Relations\Relation::flushState();
        \Hypervel\Database\Console\Migrations\ResetCommand::prohibit(false);
        \Hypervel\Auth\Notifications\ResetPassword::flushState();
        \Hypervel\Routing\ResourceRegistrar::flushState();
        \Hypervel\Http\Client\ResponseSequence::flushState();
        \Hypervel\Routing\Route::flushState();
        \Hypervel\Foundation\Support\Providers\RouteServiceProvider::flushState();
        \Hypervel\Routing\RouteSignatureParameters::flushCache();
        \Hypervel\Validation\Rule::flushState();
        \Hypervel\Sanctum\Sanctum::flushState();
        \Hypervel\Console\Scheduling\Event::flushState();
        \Hypervel\Database\Schema\Builder::flushState();
        \Hypervel\Scout\Scout::flushState();
        \Hypervel\Scout\Builder::flushState();
        \Hypervel\Database\Console\Seeds\SeedCommand::prohibit(false);
        \Hypervel\Server\ServerManager::flushState();
        \Hypervel\Support\ServiceProvider::flushState();
        \Hypervel\Support\Sleep::flushState();
        \Hypervel\Routing\SortedMiddleware::flushCache();
        \Hypervel\Session\Store::flushState();
        \Hypervel\Support\Str::flushState();
        \Hypervel\Support\StrCache::flushState();
        \Hypervel\Horizon\SupervisorCommandString::flushState();
        \Hypervel\Telescope\Telescope::flushState();
        \Hypervel\Testing\TestResponse::flushState();
        \Hypervel\Foundation\Http\Middleware\TrimStrings::flushState();
        \Hypervel\Http\Middleware\TrustHosts::flushState();
        \Hypervel\Http\Middleware\TrustProxies::flushState();
        \Hypervel\Support\Uri::flushState();
        \Hypervel\Routing\UrlGenerator::flushRequestState();
        \Hypervel\Routing\Middleware\ValidateSignature::flushState();
        \Hypervel\Validation\Validator::flushState();
        \Hypervel\Auth\Notifications\VerifyEmail::flushState();
        \Hypervel\Foundation\Vite::flush();
        \Hypervel\Database\Console\WipeCommand::prohibit(false);
        \Hypervel\Queue\Console\WorkCommand::flushState();
        \Hypervel\Foundation\WorkerCachedMaintenanceMode::flushCache();
        \Hypervel\Horizon\WorkerCommandString::flushState();
    }
}
