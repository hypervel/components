<?php

declare(strict_types=1);

namespace Hypervel\Testing\PHPUnit;

use Hypervel\Coroutine\Coroutine;
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
            if (class_exists(Mockery::class)) {
                Mockery::close();
            }
        } catch (Throwable $throwable) {
            $exception = Coroutine::preferCancellation($exception, $throwable);
        }

        try {
            if (class_exists(DatabaseConnectionResolver::class, false)) {
                DatabaseConnectionResolver::flushCachedConnections();
            }
        } catch (Throwable $throwable) {
            $exception = Coroutine::preferCancellation($exception, $throwable);
        }

        try {
            $this->flushFrameworkState();
        } catch (Throwable $throwable) {
            $exception = Coroutine::preferCancellation($exception, $throwable);
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
        // Cancel while the owning context still exists.
        if (class_exists(\Hypervel\Http\Client\Guzzle\CoroutineState::class, false)) {
            \Hypervel\Http\Client\Guzzle\CoroutineState::current()?->cancel();
        }
        $queue = \GuzzleHttp\Promise\Utils::queue();
        if ($queue instanceof \Hypervel\Http\Client\Guzzle\CoroutineTaskQueue) {
            $queue->resetOutside();
        }

        // Mutable and immutable Carbon dates share the default factory.
        if (class_exists(\Carbon\FactoryImmutable::class, false)) {
            $factory = \Carbon\FactoryImmutable::getDefaultInstance();
            $factory->resetMacros();
            $factory->resetToStringFormat();
            $factory->serializeUsing(null);
            $factory->useStrictMode();
            $factory->setTestNow();
        }

        if (class_exists(\Laravel\SerializableClosure\SerializableClosure::class)) {
            \Laravel\SerializableClosure\SerializableClosure::setSecretKey(null);
            \Laravel\SerializableClosure\SerializableClosure::transformUseVariablesUsing(null);
            \Laravel\SerializableClosure\SerializableClosure::resolveUseVariablesUsing(null);
        }

        $this->callIfLoaded(\Hypervel\Auth\Access\Gate::class);
        $this->callIfLoaded(\Hypervel\Auth\AuthenticationException::class);
        $this->callIfLoaded(\Hypervel\Auth\EloquentUserProvider::class);
        $this->callIfLoaded(\Hypervel\Auth\Middleware\Authenticate::class);
        $this->callIfLoaded(\Hypervel\Auth\Middleware\RedirectIfAuthenticated::class);
        $this->callIfLoaded(\Hypervel\Auth\Notifications\ResetPassword::class);
        $this->callIfLoaded(\Hypervel\Auth\Notifications\VerifyEmail::class);
        $this->callIfLoaded(\Hypervel\Auth\RequestGuard::class);
        $this->callIfLoaded(\Hypervel\Auth\SessionGuard::class);
        $this->callIfLoaded(\Hypervel\Auth\TokenGuard::class);
        $this->callIfLoaded(\Hypervel\Broadcasting\Broadcasters\Broadcaster::class);
        $this->callIfLoaded(\Hypervel\Bus\DispatchLockContext::class);
        $this->callIfLoaded(\Hypervel\Bus\PendingBatch::class);
        $this->callIfLoaded(\Hypervel\Cache\Console\ClearCommand::class);
        $this->callIfLoaded(\Hypervel\Cache\Redis\Console\BenchmarkCommand::class);
        $this->callIfLoaded(\Hypervel\Cache\Redis\Console\DoctorCommand::class);
        $this->callIfLoaded(\Hypervel\Cache\Repository::class);
        $this->callIfLoaded(\Hypervel\Config\Repository::class);
        $this->callIfLoaded(\Hypervel\Console\Application::class, 'forgetBootstrappers');
        $this->callIfLoaded(\Hypervel\Console\Command::class);
        $this->callIfLoaded(\Hypervel\Console\Commands\ScheduleListCommand::class);
        $this->callIfLoaded(\Hypervel\Console\Scheduling\Event::class);
        $this->callIfLoaded(\Hypervel\Console\Scheduling\Schedule::class);
        $this->callIfLoaded(\Hypervel\Container\BoundMethod::class);
        if (class_exists(\Hypervel\Container\Container::class, false)) {
            \Hypervel\Container\Container::flushState();
            \Hypervel\Container\Container::setInstance(null);
        }
        \Hypervel\Context\CoroutineContext::flush();
        $this->callIfLoaded(\Hypervel\Contracts\Database\ModelIdentifier::class);
        $this->callIfLoaded(\Hypervel\Cookie\CookieJar::class);
        $this->callIfLoaded(\Hypervel\Cookie\Middleware\EncryptCookies::class);
        $this->callIfLoaded(\Hypervel\Coordinator\CoordinatorManager::class);
        $this->callIfLoaded(\Hypervel\Coordinator\Timer::class);
        $this->callIfLoaded(\Hypervel\Coroutine\Channel\Pool::class);
        $this->callIfLoaded(\Hypervel\Coroutine\Coroutine::class);
        $this->callIfLoaded(\Hypervel\Coroutine\Locker::class);
        $this->callIfLoaded(\Hypervel\Coroutine\Mutex::class);
        $this->callIfLoaded(\Hypervel\Database\Capsule\Manager::class);
        // Other drivers can change the parent state that the PDO reset normally clears.
        if (class_exists(\Hypervel\Database\PdoConnection::class, false)) {
            \Hypervel\Database\PdoConnection::flushState();
        } else {
            $this->callIfLoaded(\Hypervel\Database\Connection::class);
        }
        $this->callIfLoaded(\Hypervel\Database\Console\DumpCommand::class);
        $this->callIfLoaded(\Hypervel\Database\Console\Migrations\FreshCommand::class);
        $this->callIfLoaded(\Hypervel\Database\Console\Migrations\RefreshCommand::class);
        $this->callIfLoaded(\Hypervel\Database\Console\Migrations\ResetCommand::class);
        $this->callIfLoaded(\Hypervel\Database\Console\Migrations\RollbackCommand::class);
        $this->callIfLoaded(\Hypervel\Database\Console\Seeds\SeedCommand::class);
        $this->callIfLoaded(\Hypervel\Database\Console\WipeCommand::class);
        $this->callIfLoaded(\Hypervel\Database\DatabaseManager::class);
        $this->callIfLoaded(\Hypervel\Database\Eloquent\Builder::class);
        $this->callIfLoaded(\Hypervel\Database\Eloquent\Casts\Json::class);
        $this->callIfLoaded(\Hypervel\Database\Eloquent\Factories\Factory::class);
        $this->callIfLoaded(\Hypervel\Database\Eloquent\Model::class);
        $this->callIfLoaded(\Hypervel\Database\Eloquent\Relations\Relation::class);
        $this->callIfLoaded(\Hypervel\Database\Grammar::class);
        $this->callIfLoaded(\Hypervel\Database\LostConnectionDetector::class);
        $this->callIfLoaded(\Hypervel\Database\Migrations\Migrator::class);
        $this->callIfLoaded(\Hypervel\Database\Query\Builder::class);
        $this->callIfLoaded(\Hypervel\Database\Query\Grammars\PostgresGrammar::class);
        $this->callIfLoaded(\Hypervel\Database\Schema\Blueprint::class);
        $this->callIfLoaded(\Hypervel\Database\Schema\Builder::class);
        $this->callIfLoaded(\Hypervel\Di\Aop\AspectCollector::class);
        $this->callIfLoaded(\Hypervel\Di\Aop\AspectManager::class);
        $this->callIfLoaded(\Hypervel\Di\Aop\AstVisitorRegistry::class);
        $this->callIfLoaded(\Hypervel\Di\ClassMap\ClassMapManager::class);
        $this->callIfLoaded(\Hypervel\Encryption\Commands\KeyGenerateCommand::class);
        $this->callIfLoaded(\Hypervel\Events\Dispatcher::class);
        $this->callIfLoaded(\Hypervel\Filesystem\Filesystem::class);
        $this->callIfLoaded(\Hypervel\Filesystem\FilesystemAdapter::class);
        $this->callIfLoaded(\Hypervel\Filesystem\FilesystemManager::class);
        $this->callIfLoaded(\Hypervel\Foundation\Application::class);
        $this->callIfLoaded(\Hypervel\Foundation\Bootstrap\LoadConfiguration::class);
        $this->callIfLoaded(\Hypervel\Foundation\Bootstrap\RegisterProviders::class);
        $this->callIfLoaded(\Hypervel\Foundation\Console\AboutCommand::class);
        $this->callIfLoaded(\Hypervel\Foundation\Console\ChannelListCommand::class);
        $this->callIfLoaded(\Hypervel\Foundation\Console\CliDumper::class);
        $this->callIfLoaded(\Hypervel\Foundation\Console\DevCommand::class);
        $this->callIfLoaded(\Hypervel\Foundation\Console\EventListCommand::class);
        $this->callIfLoaded(\Hypervel\Foundation\Console\RouteListCommand::class);
        $this->callIfLoaded(\Hypervel\Foundation\Console\VendorPublishCommand::class);
        $this->callIfLoaded(\Hypervel\Foundation\DevCommands::class);
        $this->callIfLoaded(\Hypervel\Foundation\Events\DiscoverEvents::class);
        $this->callIfLoaded(\Hypervel\Foundation\Http\FormRequest::class);
        $this->callIfLoaded(\Hypervel\Foundation\Http\HtmlDumper::class);
        $this->callIfLoaded(\Hypervel\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class);
        $this->callIfLoaded(\Hypervel\Foundation\Http\Middleware\PreventRequestForgery::class);
        $this->callIfLoaded(\Hypervel\Foundation\Http\Middleware\PreventRequestsDuringMaintenance::class);
        $this->callIfLoaded(\Hypervel\Foundation\Http\Middleware\TrimStrings::class);
        $this->callIfLoaded(\Hypervel\Foundation\PackageManifest::class);
        $this->callIfLoaded(\Hypervel\Foundation\Support\Providers\EventServiceProvider::class);
        $this->callIfLoaded(\Hypervel\Foundation\Support\Providers\RouteServiceProvider::class);
        // Vite also clears the font manifests, which ViteFonts can cache independently.
        if (class_exists(\Hypervel\Foundation\Vite::class, false)) {
            \Hypervel\Foundation\Vite::flush();
        } else {
            $this->callIfLoaded(\Hypervel\Foundation\ViteFonts::class, 'flush');
        }
        $this->callIfLoaded(\Hypervel\Foundation\WorkerCachedMaintenanceMode::class, 'flushCache');
        $this->callIfLoaded(\Hypervel\Http\Client\Destinations\CurlCapabilities::class);
        $this->callIfLoaded(\Hypervel\Http\Client\Factory::class);
        $this->callIfLoaded(\Hypervel\Http\Client\PendingRequest::class);
        $this->callIfLoaded(\Hypervel\Http\Client\Request::class);
        $this->callIfLoaded(\Hypervel\Http\Client\RequestException::class);
        $this->callIfLoaded(\Hypervel\Http\Client\Response::class);
        $this->callIfLoaded(\Hypervel\Http\Client\ResponseSequence::class);
        $this->callIfLoaded(\Hypervel\Http\JsonResponse::class);
        $this->callIfLoaded(\Hypervel\Http\Middleware\HandleCors::class);
        $this->callIfLoaded(\Hypervel\Http\Middleware\TrustHosts::class);
        $this->callIfLoaded(\Hypervel\Http\Middleware\TrustProxies::class);
        $this->callIfLoaded(\Hypervel\Http\RedirectResponse::class);
        // Hypervel's reset also clears settings on the Symfony request.
        if (class_exists(\Symfony\Component\HttpFoundation\Request::class, false)) {
            \Hypervel\Http\Request::flushState();
        }
        $this->callIfLoaded(\Hypervel\Http\Resources\Json\JsonResource::class);
        $this->callIfLoaded(\Hypervel\Http\Resources\JsonApi\JsonApiResource::class);
        $this->callIfLoaded(\Hypervel\Http\Response::class);
        $this->callIfLoaded(\Hypervel\Http\UploadedFile::class);
        $this->callIfLoaded(\Hypervel\Log\Context\Repository::class);
        $this->callIfLoaded(\Hypervel\Mail\Attachment::class);
        $this->callIfLoaded(\Hypervel\Mail\Mailable::class);
        $this->callIfLoaded(\Hypervel\Mail\Mailer::class);
        $this->callIfLoaded(\Hypervel\Mail\Markdown::class);
        $this->callIfLoaded(\Hypervel\Notifications\ChannelManager::class);
        $this->callIfLoaded(\Hypervel\Pagination\AbstractCursorPaginator::class);
        $this->callIfLoaded(\Hypervel\Pagination\AbstractPaginator::class);
        $this->callIfLoaded(\Hypervel\Pipeline\Pipeline::class);
        $this->callIfLoaded(\Hypervel\Process\Factory::class);
        $this->callIfLoaded(\Hypervel\Process\InvokedProcess::class);
        $this->callIfLoaded(\Hypervel\Process\PendingProcess::class);
        $this->callIfLoaded(\Hypervel\Prompts\Prompt::class);
        $this->callIfLoaded(\Hypervel\Prompts\Terminal::class);
        $this->callIfLoaded(\Hypervel\Queue\Capsule\Manager::class);
        $this->callIfLoaded(\Hypervel\Queue\Console\ClearCommand::class);
        $this->callIfLoaded(\Hypervel\Queue\Console\FlushFailedCommand::class);
        $this->callIfLoaded(\Hypervel\Queue\Console\WorkCommand::class);
        $this->callIfLoaded(\Hypervel\Queue\Queue::class);
        $this->callIfLoaded(\Hypervel\Queue\Worker::class);
        $this->callIfLoaded(\Hypervel\RateLimiter\RateLimiter::class);
        $this->callIfLoaded(\Hypervel\Redis\RedisConnection::class, 'flushMacros');
        $this->callIfLoaded(\Hypervel\Routing\CallableDispatcher::class);
        $this->callIfLoaded(\Hypervel\Routing\ControllerDispatcher::class);
        $this->callIfLoaded(\Hypervel\Routing\ImplicitRouteBinding::class, 'flushCache');
        $this->callIfLoaded(\Hypervel\Routing\Middleware\ValidateSignature::class);
        $this->callIfLoaded(\Hypervel\Routing\PendingResourceRegistration::class);
        $this->callIfLoaded(\Hypervel\Routing\PendingSingletonResourceRegistration::class);
        $this->callIfLoaded(\Hypervel\Routing\Redirector::class);
        $this->callIfLoaded(\Hypervel\Routing\ResourceRegistrar::class);
        $this->callIfLoaded(\Hypervel\Routing\ResponseFactory::class);
        $this->callIfLoaded(\Hypervel\Routing\Route::class);
        $this->callIfLoaded(\Hypervel\Routing\RouteRegistrar::class);
        $this->callIfLoaded(\Hypervel\Routing\RouteSignatureParameters::class, 'flushCache');
        $this->callIfLoaded(\Hypervel\Routing\Router::class);
        $this->callIfLoaded(\Hypervel\Routing\SortedMiddleware::class, 'flushCache');
        $this->callIfLoaded(\Hypervel\Routing\UrlGenerator::class);
        $this->callIfLoaded(\Hypervel\Server\ServerManager::class);
        $this->callIfLoaded(\Hypervel\Server\ResponseCancellation::class);
        $this->callIfLoaded(\Hypervel\ServerProcess\ProcessCollector::class);
        $this->callIfLoaded(\Hypervel\ServerProcess\ProcessManager::class);
        $this->callIfLoaded(\Hypervel\Session\Middleware\AuthenticateSession::class);
        $this->callIfLoaded(\Hypervel\Session\Middleware\StartSession::class);
        $this->callIfLoaded(\Hypervel\Session\Store::class);
        $this->callIfLoaded(\Hypervel\Support\Arr::class);
        $this->callIfLoaded(\Hypervel\Support\Aws\SerializedCredentialProvider::class);
        $this->callIfLoaded(\Hypervel\Support\Benchmark::class);
        $this->callIfLoaded(\Hypervel\Support\BinaryCodec::class);
        $this->callIfLoaded(\Hypervel\Support\ClassMetadataCache::class);
        $this->callIfLoaded(\Hypervel\Support\ClearStatCache::class);
        $this->callIfLoaded(\Hypervel\Support\Collection::class);
        $this->callIfLoaded(\Hypervel\Support\Composer::class);
        $this->callIfLoaded(\Hypervel\Support\ConfigurationUrlParser::class);
        $this->callIfLoaded(\Hypervel\Support\DateFactory::class);
        $this->callIfLoaded(\Hypervel\Support\DotenvManager::class);
        $this->callIfLoaded(\Hypervel\Support\EncodedHtmlString::class);
        $this->callIfLoaded(\Hypervel\Support\Env::class);
        if (class_exists(\Hypervel\Support\Facades\Facade::class, false)) {
            \Hypervel\Support\Facades\Facade::clearResolvedInstances();
            \Hypervel\Support\Facades\Facade::setFacadeApplication(null);
        }
        $this->callIfLoaded(\Hypervel\Support\Fluent::class);
        $this->callIfLoaded(\Hypervel\Support\LazyCollection::class);
        $this->callIfLoaded(\Hypervel\Support\Lottery::class);
        $this->callIfLoaded(\Hypervel\Support\Number::class);
        $this->callIfLoaded(\Hypervel\Support\Optional::class);
        $this->callIfLoaded(\Hypervel\Support\Pluralizer::class);
        $this->callIfLoaded(\Hypervel\Support\ServiceProvider::class);
        $this->callIfLoaded(\Hypervel\Support\Sleep::class);
        $this->callIfLoaded(\Hypervel\Support\Str::class);
        $this->callIfLoaded(\Hypervel\Support\StrCache::class);
        $this->callIfLoaded(\Hypervel\Support\Stringable::class);
        $this->callIfLoaded(\Hypervel\Support\Testing\Fakes\NotificationFake::class);
        $this->callIfLoaded(\Hypervel\Support\Uri::class);
        $this->callIfLoaded(\Hypervel\Testing\Fluent\AssertableJson::class);
        $this->callIfLoaded(\Hypervel\Testing\ParallelRunner::class);
        $this->callIfLoaded(\Hypervel\Testing\PendingCommand::class);
        $this->callIfLoaded(\Hypervel\Testing\TestComponent::class);
        $this->callIfLoaded(\Hypervel\Testing\TestResponse::class);
        $this->callIfLoaded(\Hypervel\Testing\TestView::class);
        $this->callIfLoaded(\Hypervel\Translation\Translator::class);
        $this->callIfLoaded(\Hypervel\Validation\Rule::class);
        $this->callIfLoaded(\Hypervel\Validation\Rules\Date::class);
        $this->callIfLoaded(\Hypervel\Validation\Rules\Email::class);
        $this->callIfLoaded(\Hypervel\Validation\Rules\File::class);
        $this->callIfLoaded(\Hypervel\Validation\Rules\Password::class);
        $this->callIfLoaded(\Hypervel\Validation\RulePlanCache::class);
        $this->callIfLoaded(\Hypervel\Validation\ValidationData::class);
        $this->callIfLoaded(\Hypervel\Validation\ValidationRuleParser::class);
        $this->callIfLoaded(\Hypervel\Validation\Validator::class);
        $this->callIfLoaded(\Hypervel\View\Component::class);
        $this->callIfLoaded(\Hypervel\View\ComponentAttributeBag::class);
        $this->callIfLoaded(\Hypervel\View\DynamicComponent::class);
        $this->callIfLoaded(\Hypervel\View\Engines\CompilerEngine::class);
        $this->callIfLoaded(\Hypervel\View\Factory::class, 'flushMacros');
        $this->callIfLoaded(\Hypervel\View\View::class);
        $this->callIfLoaded(\Hypervel\WebSocketServer\Collector\FdCollector::class);
        $this->callIfLoaded(\Hypervel\WebSocketServer\Context::class);

        $this->flushAiState();
        $this->flushDataState();
        $this->flushDoctorState();
        $this->flushFortifyState();
        $this->flushHeadState();
        $this->flushHorizonState();
        $this->flushImageState();
        $this->flushInertiaState();
        $this->flushJwtState();
        $this->flushMcpState();
        $this->flushNestedSetState();
        $this->flushPasskeysState();
        $this->flushPassportState();
        $this->flushPennantState();
        $this->flushPermissionState();
        $this->flushReverbState();
        $this->flushSaloonState();
        $this->flushSanctumState();
        $this->flushScoutState();
        $this->flushSentryState();
        $this->flushTelescopeState();
        $this->flushTestbenchState();
        $this->flushWayfinderState();
    }

    /**
     * Flush AI state.
     */
    protected function flushAiState(): void
    {
        $this->callIfLoaded(\Hypervel\Ai\Files\UntrustedUrl::class);
        $this->callIfLoaded(\Hypervel\Ai\Tools\LoadSkill::class);
        $this->callIfLoaded(\Hypervel\Ai\Tools\Request::class, 'flushMacros');
    }

    /**
     * Flush Data state.
     */
    protected function flushDataState(): void
    {
        $this->callIfLoaded(\Hypervel\Data\CursorPaginatedDataCollection::class, 'flushMacros');
        $this->callIfLoaded(\Hypervel\Data\DataCollection::class, 'flushMacros');
        $this->callIfLoaded(\Hypervel\Data\Lazy::class, 'flushMacros');
        $this->callIfLoaded(\Hypervel\Data\PaginatedDataCollection::class, 'flushMacros');
    }

    /**
     * Flush Doctor state.
     */
    protected function flushDoctorState(): void
    {
        $this->callIfLoaded(\Hypervel\Doctor\DiagnosticSource::class);
    }

    /**
     * Flush Fortify state.
     */
    protected function flushFortifyState(): void
    {
        $this->callIfLoaded(\Hypervel\Fortify\Fortify::class);
    }

    /**
     * Flush Head state.
     */
    protected function flushHeadState(): void
    {
        $this->callIfLoaded(\Hypervel\Head\Tags\Canonical::class);
        $this->callIfLoaded(\Hypervel\Head\Schema\SchemaObject::class);
        $this->callIfLoaded(\Hypervel\Head\HeadManager::class, 'flushMacros');
    }

    /**
     * Flush Horizon state.
     */
    protected function flushHorizonState(): void
    {
        $this->callIfLoaded(\Hypervel\Horizon\Horizon::class);
        $this->callIfLoaded(\Hypervel\Horizon\MasterSupervisor::class);
        $this->callIfLoaded(\Hypervel\Horizon\SupervisorCommandString::class);
        $this->callIfLoaded(\Hypervel\Horizon\SystemProcessCounter::class);
        $this->callIfLoaded(\Hypervel\Horizon\WorkerCommandString::class);
    }

    /**
     * Flush Image state.
     */
    protected function flushImageState(): void
    {
        $this->callIfLoaded(\Hypervel\Image\Image::class);
    }

    /**
     * Flush Inertia state.
     */
    protected function flushInertiaState(): void
    {
        $this->callIfLoaded(\Hypervel\Inertia\DevTools\EntryStore::class);
        $this->callIfLoaded(\Hypervel\Inertia\Middleware::class);
        $this->callIfLoaded(\Hypervel\Inertia\Response::class);
        $this->callIfLoaded(\Hypervel\Inertia\ResponseFactory::class);
        $this->callIfLoaded(\Hypervel\Inertia\Ssr\BundleDetector::class);
        $this->callIfLoaded(\Hypervel\Inertia\Ssr\HttpGateway::class);
    }

    /**
     * Flush JWT state.
     */
    protected function flushJwtState(): void
    {
        $this->callIfLoaded(\Hypervel\Jwt\ClaimFactory::class);
        $this->callIfLoaded(\Hypervel\Jwt\JwtGuard::class);
    }

    /**
     * Flush MCP state.
     */
    protected function flushMcpState(): void
    {
        $this->callIfLoaded(\Hypervel\Mcp\Server::class);
        $this->callIfLoaded(\Hypervel\Mcp\Server\Middleware\AddWwwAuthenticateHeader::class);
        $this->callIfLoaded(\Hypervel\Mcp\Server\Primitive::class);
        $this->callIfLoaded(\Hypervel\Mcp\Server\Skill::class);
        $this->callIfLoaded(\Hypervel\Mcp\Request::class, 'flushMacros');
        $this->callIfLoaded(\Hypervel\Mcp\Response::class, 'flushMacros');
        $this->callIfLoaded(\Hypervel\Mcp\ResponseFactory::class, 'flushMacros');
        $this->callIfLoaded(\Hypervel\Mcp\Client\ClientManager::class, 'flushMacros');
        $this->callIfLoaded(\Hypervel\Mcp\Client\OAuth\AuthServerDiscovery::class);
        $this->callIfLoaded(\Hypervel\Mcp\Server\Registrar::class, 'flushMacros');
        $this->callIfLoaded(\Hypervel\Mcp\Server\Testing\TestResponse::class, 'flushMacros');
        $this->callIfLoaded(\Hypervel\Mcp\Server\Testing\TestListResponse::class, 'flushMacros');
    }

    /**
     * Flush Nested Set state.
     */
    protected function flushNestedSetState(): void
    {
        $this->callIfLoaded(\Hypervel\NestedSet\NestedSet::class);
    }

    /**
     * Flush Passkeys state.
     */
    protected function flushPasskeysState(): void
    {
        $this->callIfLoaded(\Hypervel\Passkeys\Passkeys::class);
        $this->callIfLoaded(\Hypervel\Passkeys\Support\Aaguids::class);
        $this->callIfLoaded(\Hypervel\Passkeys\Support\WebAuthn::class);
    }

    /**
     * Flush Passport state.
     */
    protected function flushPassportState(): void
    {
        $this->callIfLoaded(\Hypervel\Passport\Passport::class);
        $this->callIfLoaded(\Hypervel\Passport\Guards\TokenGuard::class);
    }

    /**
     * Flush Pennant state.
     */
    protected function flushPennantState(): void
    {
        $this->callIfLoaded(\Hypervel\Pennant\Drivers\Decorator::class, 'flushMacros');
        $this->callIfLoaded(\Hypervel\Pennant\Middleware\EnsureFeaturesAreActive::class);
    }

    /**
     * Flush Permission state.
     */
    protected function flushPermissionState(): void
    {
        $this->callIfLoaded(\Hypervel\Permission\Guard::class);
        $this->callIfLoaded(\Hypervel\Permission\PermissionRegistrar::class);
    }

    /**
     * Flush Reverb state.
     */
    protected function flushReverbState(): void
    {
        $this->callIfLoaded(\Hypervel\Reverb\Loggers\Log::class);
        $this->callIfLoaded(\Hypervel\Reverb\Servers\Hypervel\WebSocketHandler::class);
    }

    /**
     * Flush Saloon state.
     */
    protected function flushSaloonState(): void
    {
        $this->callIfLoaded(\Hypervel\Saloon\OAuthAccounts::class);
        $this->callIfLoaded(\Hypervel\Saloon\Http\Connector::class);
        $this->callIfLoaded(\Hypervel\Saloon\Http\PendingRequest::class);
        $this->callIfLoaded(\Hypervel\Saloon\Http\PendingRequest\BootPlugins::class);
        $this->callIfLoaded(\Hypervel\Saloon\Http\Request::class);
    }

    /**
     * Flush Sanctum state.
     */
    protected function flushSanctumState(): void
    {
        $this->callIfLoaded(\Hypervel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class);
        $this->callIfLoaded(\Hypervel\Sanctum\Sanctum::class);
        $this->callIfLoaded(\Hypervel\Sanctum\SanctumGuard::class);
    }

    /**
     * Flush Scout state.
     */
    protected function flushScoutState(): void
    {
        $this->callIfLoaded(\Hypervel\Scout\Builder::class);
        $this->callIfLoaded(\Hypervel\Scout\Scout::class);
    }

    /**
     * Flush Sentry state.
     */
    protected function flushSentryState(): void
    {
        $this->callIfExists(\Hypervel\Sentry\Features\HttpClientIntegration::class, 'flushState');
        if (class_exists(\Sentry\SentrySdk::class, false)) {
            \Sentry\SentrySdk::setRuntimeContextStorage(null);
            \Sentry\SentrySdk::init();
        }
    }

    /**
     * Flush Telescope state.
     */
    protected function flushTelescopeState(): void
    {
        // Avatar's callback can be registered directly or through Telescope.
        if (class_exists(\Hypervel\Telescope\Telescope::class, false)) {
            \Hypervel\Telescope\Telescope::flushState();
        } else {
            $this->callIfLoaded(\Hypervel\Telescope\Avatar::class);
        }
        $this->callIfExists(\Hypervel\Telescope\Watchers\ClientRequestWatcher::class, 'flushState');
        $this->callIfExists(\Hypervel\Telescope\Watchers\DumpWatcher::class, 'flushState');
        $this->callIfLoaded(\Hypervel\Telescope\Watchers\RedisWatcher::class);
    }

    /**
     * Flush Testbench state.
     */
    protected function flushTestbenchState(): void
    {
        $this->callIfLoaded(\Hypervel\Testbench\Bootstrapper::class);
        $this->callIfLoaded(\Hypervel\Testbench\Factories\UserFactory::class);
        $this->callIfLoaded(\Hypervel\Testbench\Foundation\Config::class);
        $this->callIfLoaded(\Hypervel\Testbench\Foundation\Console\TerminatingConsole::class, 'flush');
        $this->callIfLoaded(\Hypervel\Testbench\Workbench\Workbench::class, 'flush');
    }

    /**
     * Flush Wayfinder state.
     */
    protected function flushWayfinderState(): void
    {
        $this->callIfLoaded(\Hypervel\Wayfinder\BindingResolver::class);
    }

    /**
     * Reset class-owned state without autoloading unused classes during teardown.
     *
     * @param class-string $class
     */
    protected function callIfLoaded(string $class, string $method = 'flushState'): void
    {
        if (class_exists($class, false)) {
            $class::$method();
        }
    }

    /**
     * Call a static cleanup method when the class is installed.
     */
    protected function callIfExists(string $class, string $method, mixed ...$arguments): void
    {
        if (class_exists($class)) {
            $class::$method(...$arguments);
        }
    }
}
