<?php

declare(strict_types=1);

namespace Hypervel\Testing\PHPUnit;

use Hypervel\Foundation\Testing\DatabaseConnectionResolver;
use Mockery;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use Throwable;

/**
 * Coordinates exactly one global cleanup after every test method.
 *
 * Prepared tests clean after PHPUnit finishes them. Tests that never become
 * prepared clean before the next test captures global state, or when the test
 * runner finishes if there is no next test.
 */
class AfterEachTestSubscriber implements FinishedSubscriber
{
    /**
     * Whether the current or most recent test still needs global cleanup.
     */
    protected bool $cleanupPending = false;

    /**
     * Clean up static state after a test finishes.
     */
    public function notify(Finished $event): void
    {
        $this->flushPendingCleanup();
    }

    /**
     * Handle internal preparation-started coordination for the PHPUnit extension.
     */
    public function handlePreparationStarted(): void
    {
        try {
            $this->flushPendingCleanup();
        } finally {
            $this->cleanupPending = true;
        }
    }

    /**
     * Handle internal execution-finished coordination for the PHPUnit extension.
     */
    public function handleExecutionFinished(): void
    {
        $this->flushPendingCleanup();
    }

    /**
     * Flush pending cleanup at most once.
     */
    protected function flushPendingCleanup(): void
    {
        if (! $this->cleanupPending) {
            return;
        }

        $this->cleanupPending = false;
        $this->flushStateAfterTest();
    }

    /**
     * Flush all test state after a test finishes.
     */
    public function flushStateAfterTest(): void
    {
        $exception = null;

        try {
            AfterEachTestCleanup::runCallbacks();
        } catch (Throwable $throwable) {
            $exception = $throwable;
        }

        try {
            Mockery::close();
        } catch (Throwable $throwable) {
            $exception ??= $throwable;
        }

        try {
            DatabaseConnectionResolver::flushCachedConnections();
        } catch (Throwable $throwable) {
            $exception ??= $throwable;
        }

        try {
            $this->flushFrameworkState();
        } catch (Throwable $throwable) {
            $exception ??= $throwable;
        }

        if ($exception !== null) {
            throw $exception;
        }
    }

    /**
     * Flush framework-owned static state.
     */
    protected function flushFrameworkState(): void
    {
        \Carbon\Carbon::resetMacros();
        \Carbon\Carbon::resetToStringFormat();
        \Carbon\Carbon::serializeUsing(null);
        \Carbon\Carbon::setTestNow();
        \Carbon\CarbonImmutable::setTestNow();

        if (class_exists(\Laravel\SerializableClosure\SerializableClosure::class)) {
            \Laravel\SerializableClosure\SerializableClosure::setSecretKey(null);
            \Laravel\SerializableClosure\SerializableClosure::transformUseVariablesUsing(null);
            \Laravel\SerializableClosure\SerializableClosure::resolveUseVariablesUsing(null);
        }

        \Hypervel\Auth\Access\Gate::flushState();
        \Hypervel\Auth\AuthenticationException::flushState();
        \Hypervel\Auth\EloquentUserProvider::flushState();
        \Hypervel\Auth\Middleware\Authenticate::flushState();
        \Hypervel\Auth\Middleware\RedirectIfAuthenticated::flushState();
        \Hypervel\Auth\Notifications\ResetPassword::flushState();
        \Hypervel\Auth\Notifications\VerifyEmail::flushState();
        \Hypervel\Auth\RequestGuard::flushState();
        \Hypervel\Auth\SessionGuard::flushState();
        \Hypervel\Auth\TokenGuard::flushState();
        \Hypervel\Broadcasting\Broadcasters\Broadcaster::flushChannels();
        \Hypervel\Bus\PendingBatch::flushState();
        \Hypervel\Bus\UniqueJobPayloadContext::flushState();
        \Hypervel\Cache\Redis\Console\BenchmarkCommand::flushState();
        \Hypervel\Cache\Redis\Console\DoctorCommand::flushState();
        \Hypervel\Cache\Repository::flushState();
        \Hypervel\Config\Repository::flushState();
        \Hypervel\Console\Application::forgetBootstrappers();
        \Hypervel\Console\Command::flushState();
        \Hypervel\Console\Commands\ScheduleListCommand::flushState();
        \Hypervel\Console\Scheduling\Event::flushState();
        \Hypervel\Console\Scheduling\Schedule::flushState();
        \Hypervel\Container\BoundMethod::flushState();
        \Hypervel\Container\Container::flushState();
        \Hypervel\Container\Container::setInstance(null);
        \Hypervel\Context\CoroutineContext::flush();
        \Hypervel\Contracts\Database\ModelIdentifier::flushState();
        \Hypervel\Cookie\CookieJar::flushState();
        \Hypervel\Cookie\Middleware\EncryptCookies::flushState();
        \Hypervel\Coordinator\CoordinatorManager::flushState();
        \Hypervel\Coordinator\Timer::flushState();
        \Hypervel\Coroutine\Channel\Pool::flushState();
        \Hypervel\Coroutine\Coroutine::flushState();
        \Hypervel\Coroutine\Locker::flushState();
        \Hypervel\Coroutine\Mutex::flushState();
        \Hypervel\Database\Capsule\Manager::flushState();
        \Hypervel\Database\Connection::flushState();
        \Hypervel\Database\Console\DumpCommand::flushState();
        \Hypervel\Database\Console\Migrations\FreshCommand::flushState();
        \Hypervel\Database\Console\Migrations\RefreshCommand::flushState();
        \Hypervel\Database\Console\Migrations\ResetCommand::flushState();
        \Hypervel\Database\Console\Migrations\RollbackCommand::flushState();
        \Hypervel\Database\Console\Seeds\SeedCommand::flushState();
        \Hypervel\Database\Console\WipeCommand::flushState();
        \Hypervel\Database\DatabaseManager::flushState();
        \Hypervel\Database\Eloquent\Builder::flushState();
        \Hypervel\Database\Eloquent\Casts\Json::flushState();
        \Hypervel\Database\Eloquent\Factories\Factory::flushState();
        \Hypervel\Database\Eloquent\Model::flushState();
        \Hypervel\Database\Eloquent\Relations\Relation::flushState();
        \Hypervel\Database\Grammar::flushState();
        \Hypervel\Database\Migrations\Migrator::flushState();
        \Hypervel\Database\Query\Builder::flushState();
        \Hypervel\Database\Query\Grammars\PostgresGrammar::flushState();
        \Hypervel\Database\Schema\Blueprint::flushState();
        \Hypervel\Database\Schema\Builder::flushState();
        \Hypervel\Database\Seeder::flushState();
        \Hypervel\Di\Aop\AspectCollector::flushState();
        \Hypervel\Di\Aop\AspectManager::flushState();
        \Hypervel\Di\Aop\AstVisitorRegistry::flushState();
        \Hypervel\Di\ClassMap\ClassMapManager::flushState();
        \Hypervel\Encryption\Commands\KeyGenerateCommand::flushState();
        \Hypervel\Events\Dispatcher::flushState();
        \Hypervel\Filesystem\Filesystem::flushState();
        \Hypervel\Filesystem\FilesystemAdapter::flushState();
        \Hypervel\Filesystem\FilesystemManager::flushState();
        \Hypervel\Foundation\Application::flushState();
        \Hypervel\Foundation\Bootstrap\LoadConfiguration::flushState();
        \Hypervel\Foundation\Bootstrap\RegisterProviders::flushState();
        \Hypervel\Foundation\Console\AboutCommand::flushState();
        \Hypervel\Foundation\Console\ChannelListCommand::flushState();
        \Hypervel\Foundation\Console\CliDumper::flushState();
        \Hypervel\Foundation\Console\DevCommand::flushState();
        \Hypervel\Foundation\Console\EventListCommand::flushState();
        \Hypervel\Foundation\Console\RouteListCommand::flushState();
        \Hypervel\Foundation\Console\VendorPublishCommand::flushState();
        \Hypervel\Foundation\DevCommands::flushState();
        \Hypervel\Foundation\Events\DiscoverEvents::flushState();
        \Hypervel\Foundation\Exceptions\Renderer\Frame::flushState();
        \Hypervel\Foundation\Http\FormRequest::flushState();
        \Hypervel\Foundation\Http\HtmlDumper::flushState();
        \Hypervel\Foundation\Http\Middleware\ConvertEmptyStringsToNull::flushState();
        \Hypervel\Foundation\Http\Middleware\PreventRequestForgery::flushState();
        \Hypervel\Foundation\Http\Middleware\PreventRequestsDuringMaintenance::flushState();
        \Hypervel\Foundation\Http\Middleware\TrimStrings::flushState();
        \Hypervel\Foundation\PackageManifest::flushState();
        \Hypervel\Foundation\Support\Providers\EventServiceProvider::flushState();
        \Hypervel\Foundation\Support\Providers\RouteServiceProvider::flushState();
        \Hypervel\Foundation\Vite::flush();
        \Hypervel\Foundation\WorkerCachedMaintenanceMode::flushCache();
        \Hypervel\Http\Client\Factory::flushState();
        \Hypervel\Http\Client\PendingRequest::flushState();
        \Hypervel\Http\Client\Request::flushState();
        \Hypervel\Http\Client\RequestException::flushState();
        \Hypervel\Http\Client\Response::flushState();
        \Hypervel\Http\Client\ResponseSequence::flushState();
        \Hypervel\Http\JsonResponse::flushState();
        \Hypervel\Http\Middleware\HandleCors::flushState();
        \Hypervel\Http\Middleware\TrustHosts::flushState();
        \Hypervel\Http\Middleware\TrustProxies::flushState();
        \Hypervel\Http\RedirectResponse::flushState();
        \Hypervel\Http\Request::flushState();
        \Hypervel\Http\Resources\Json\JsonResource::flushState();
        \Hypervel\Http\Resources\JsonApi\JsonApiResource::flushState();
        \Hypervel\Http\Response::flushState();
        \Hypervel\Http\UploadedFile::flushState();
        \Hypervel\Jwt\ClaimFactory::flushState();
        \Hypervel\Jwt\JwtGuard::flushState();
        \Hypervel\Log\Context\Repository::flushState();
        \Hypervel\Mail\Attachment::flushState();
        \Hypervel\Mail\Mailable::flushState();
        \Hypervel\Mail\Mailer::flushState();
        \Hypervel\Mail\Markdown::flushState();
        \Hypervel\Notifications\ChannelManager::flushState();
        \Hypervel\Pagination\AbstractCursorPaginator::flushState();
        \Hypervel\Pagination\AbstractPaginator::flushState();
        \Hypervel\Pipeline\Pipeline::flushState();
        \Hypervel\Process\Factory::flushState();
        \Hypervel\Process\InvokedProcess::flushState();
        \Hypervel\Prompts\Prompt::flushState();
        \Hypervel\Prompts\Terminal::flushState();
        \Hypervel\Queue\Capsule\Manager::flushState();
        \Hypervel\Queue\Console\WorkCommand::flushState();
        \Hypervel\Queue\Queue::flushState();
        \Hypervel\Queue\Worker::flushState();
        \Hypervel\Routing\CallableDispatcher::flushState();
        \Hypervel\Routing\ControllerDispatcher::flushState();
        \Hypervel\Routing\ImplicitRouteBinding::flushCache();
        \Hypervel\Routing\Middleware\ThrottleRequests::flushState();
        \Hypervel\Routing\Middleware\ValidateSignature::flushState();
        \Hypervel\Routing\PendingResourceRegistration::flushState();
        \Hypervel\Routing\PendingSingletonResourceRegistration::flushState();
        \Hypervel\Routing\Redirector::flushState();
        \Hypervel\Routing\ResourceRegistrar::flushState();
        \Hypervel\Routing\ResponseFactory::flushState();
        \Hypervel\Routing\Route::flushState();
        \Hypervel\Routing\RouteRegistrar::flushState();
        \Hypervel\Routing\RouteSignatureParameters::flushCache();
        \Hypervel\Routing\Router::flushState();
        \Hypervel\Routing\SortedMiddleware::flushCache();
        \Hypervel\Routing\UrlGenerator::flushState();
        \Hypervel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::flushState();
        \Hypervel\Server\ServerManager::flushState();
        \Hypervel\ServerProcess\ProcessCollector::flushState();
        \Hypervel\ServerProcess\ProcessManager::flushState();
        \Hypervel\Session\Middleware\AuthenticateSession::flushState();
        \Hypervel\Session\Middleware\StartSession::flushState();
        \Hypervel\Session\Store::flushState();
        \Hypervel\Support\Arr::flushState();
        \Hypervel\Support\Benchmark::flushState();
        \Hypervel\Support\BinaryCodec::flushState();
        \Hypervel\Support\ClassMetadataCache::flushState();
        \Hypervel\Support\ClearStatCache::flushState();
        \Hypervel\Support\Collection::flushState();
        \Hypervel\Support\Composer::flushState();
        \Hypervel\Support\ConfigurationUrlParser::flushState();
        \Hypervel\Support\DataObject::flushState();
        \Hypervel\Support\DateFactory::flushState();
        \Hypervel\Support\DotenvManager::flushState();
        \Hypervel\Support\EncodedHtmlString::flushState();
        \Hypervel\Support\Env::flushState();
        \Hypervel\Support\Facades\Facade::clearResolvedInstances();
        \Hypervel\Support\Fluent::flushState();
        \Hypervel\Support\LazyCollection::flushState();
        \Hypervel\Support\Lottery::flushState();
        \Hypervel\Support\Number::flushState();
        \Hypervel\Support\Optional::flushState();
        \Hypervel\Support\Pluralizer::flushState();
        \Hypervel\Support\ServiceProvider::flushState();
        \Hypervel\Support\Sleep::flushState();
        \Hypervel\Support\Str::flushState();
        \Hypervel\Support\StrCache::flushState();
        \Hypervel\Support\Stringable::flushState();
        \Hypervel\Support\Testing\Fakes\NotificationFake::flushState();
        \Hypervel\Support\Uri::flushState();
        \Hypervel\Testing\Fluent\AssertableJson::flushState();
        \Hypervel\Testing\ParallelRunner::flushState();
        \Hypervel\Testing\PendingCommand::flushState();
        \Hypervel\Testing\TestComponent::flushState();
        \Hypervel\Testing\TestResponse::flushState();
        \Hypervel\Testing\TestView::flushState();
        \Hypervel\Translation\Translator::flushState();
        \Hypervel\Validation\Console\BenchmarkValidationCommand::flushState();
        \Hypervel\Validation\Rule::flushState();
        \Hypervel\Validation\Rules\Date::flushState();
        \Hypervel\Validation\Rules\Email::flushState();
        \Hypervel\Validation\Rules\File::flushState();
        \Hypervel\Validation\Rules\Password::flushState();
        \Hypervel\Validation\RulePlanCache::flushState();
        \Hypervel\Validation\ValidationRuleParser::flushState();
        \Hypervel\Validation\Validator::flushState();
        \Hypervel\View\Component::flushCache();
        \Hypervel\View\Component::forgetComponentsResolver();
        \Hypervel\View\Component::forgetFactory();
        \Hypervel\View\ComponentAttributeBag::flushState();
        \Hypervel\View\DynamicComponent::flushState();
        \Hypervel\View\Engines\CompilerEngine::forgetCompiledOrNotExpired();
        \Hypervel\View\Factory::flushMacros();
        \Hypervel\View\View::flushState();
        \Hypervel\WebSocketServer\Collector\FdCollector::flushState();
        \Hypervel\WebSocketServer\Context::flushState();

        $this->flushFortifyState();
        $this->flushHorizonState();
        $this->flushInertiaState();
        $this->flushNestedSetState();
        $this->flushPasskeysState();
        $this->flushPermissionState();
        $this->flushReverbState();
        $this->flushSanctumState();
        $this->flushScoutState();
        $this->flushSentryState();
        $this->flushTelescopeState();
        $this->flushTestbenchState();
        $this->flushWayfinderState();
    }

    /**
     * Flush Fortify state.
     */
    protected function flushFortifyState(): void
    {
        $this->callIfExists(\Hypervel\Fortify\Fortify::class, 'flushState');
    }

    /**
     * Flush Horizon state.
     */
    protected function flushHorizonState(): void
    {
        $this->callIfExists(\Hypervel\Horizon\Horizon::class, 'flushState');
        $this->callIfExists(\Hypervel\Horizon\MasterSupervisor::class, 'flushState');
        $this->callIfExists(\Hypervel\Horizon\SupervisorCommandString::class, 'flushState');
        $this->callIfExists(\Hypervel\Horizon\SystemProcessCounter::class, 'flushState');
        $this->callIfExists(\Hypervel\Horizon\WorkerCommandString::class, 'flushState');
    }

    /**
     * Flush Inertia state.
     */
    protected function flushInertiaState(): void
    {
        $this->callIfExists(\Hypervel\Inertia\Middleware::class, 'flushState');
        $this->callIfExists(\Hypervel\Inertia\Response::class, 'flushState');
        $this->callIfExists(\Hypervel\Inertia\ResponseFactory::class, 'flushState');
        $this->callIfExists(\Hypervel\Inertia\Ssr\BundleDetector::class, 'flushState');
        $this->callIfExists(\Hypervel\Inertia\Ssr\HttpGateway::class, 'flushState');
    }

    /**
     * Flush Nested Set state.
     */
    protected function flushNestedSetState(): void
    {
        $this->callIfExists(\Hypervel\NestedSet\Eloquent\BaseRelation::class, 'flushState');
    }

    /**
     * Flush Passkeys state.
     */
    protected function flushPasskeysState(): void
    {
        $this->callIfExists(\Hypervel\Passkeys\Passkeys::class, 'flushState');
        $this->callIfExists(\Hypervel\Passkeys\Support\Aaguids::class, 'flushState');
        $this->callIfExists(\Hypervel\Passkeys\Support\WebAuthn::class, 'flushState');
    }

    /**
     * Flush Permission state.
     */
    protected function flushPermissionState(): void
    {
        $this->callIfExists(\Hypervel\Permission\DefaultTeamResolver::class, 'flushState');
        $this->callIfExists(\Hypervel\Permission\Guard::class, 'flushState');
        $this->callIfExists(\Hypervel\Permission\PermissionRegistrar::class, 'flushState');
    }

    /**
     * Flush Reverb state.
     */
    protected function flushReverbState(): void
    {
        $this->callIfExists(\Hypervel\Reverb\Loggers\Log::class, 'flushState');
        $this->callIfExists(\Hypervel\Reverb\Servers\Hypervel\WebSocketHandler::class, 'flushState');
    }

    /**
     * Flush Sanctum state.
     */
    protected function flushSanctumState(): void
    {
        $this->callIfExists(\Hypervel\Sanctum\Sanctum::class, 'flushState');
        $this->callIfExists(\Hypervel\Sanctum\SanctumGuard::class, 'flushState');
    }

    /**
     * Flush Scout state.
     */
    protected function flushScoutState(): void
    {
        $this->callIfExists(\Hypervel\Scout\Builder::class, 'flushState');
        $this->callIfExists(\Hypervel\Scout\Scout::class, 'flushState');
    }

    /**
     * Flush Sentry state.
     */
    protected function flushSentryState(): void
    {
        $this->callIfExists(\Hypervel\Sentry\Http\HypervelRequestFetcher::class, 'flushState');
        $this->callIfExists(\Hypervel\Sentry\Tracing\Middleware::class, 'flushState');
    }

    /**
     * Flush Telescope state.
     */
    protected function flushTelescopeState(): void
    {
        $this->callIfExists(\Hypervel\Telescope\Telescope::class, 'flushState');
        $this->callIfExists(\Hypervel\Telescope\Watchers\CacheWatcher::class, 'flushState');
        $this->callIfExists(\Hypervel\Telescope\Watchers\RedisWatcher::class, 'flushState');
    }

    /**
     * Flush Testbench state.
     */
    protected function flushTestbenchState(): void
    {
        $this->callIfExists(\Hypervel\Testbench\Bootstrapper::class, 'flushState');
        $this->callIfExists(\Hypervel\Testbench\Factories\UserFactory::class, 'flushState');
        $this->callIfExists(\Hypervel\Testbench\Foundation\Config::class, 'flushState');
        $this->callIfExists(\Hypervel\Testbench\Foundation\Console\TerminatingConsole::class, 'flush');
        $this->callIfExists(\Hypervel\Testbench\Workbench\Workbench::class, 'flush');
    }

    /**
     * Flush Wayfinder state.
     */
    protected function flushWayfinderState(): void
    {
        $this->callIfExists(\Hypervel\Wayfinder\BindingResolver::class, 'flushState');
    }

    /**
     * Call a static cleanup method when the class is installed.
     */
    protected function callIfExists(string $class, string $method, mixed ...$arguments): void
    {
        if (class_exists($class) && method_exists($class, $method)) {
            $class::$method(...$arguments);
        }
    }
}
