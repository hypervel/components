<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Coroutine;

use App\User;
use Hypervel\Bus\Dispatcher as BusDispatcher;
use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Bus\Dispatcher as BusDispatcherContract;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Events\Dispatcher;
use Hypervel\HttpServer\Events\RequestHandled;
use Hypervel\HttpServer\Events\RequestReceived;
use Hypervel\PHPStan\Collectors\WorkerStateCallCollector;
use Hypervel\PHPStan\Collectors\WorkerStateClassCollector;
use Hypervel\PHPStan\Collectors\WorkerStateConfigOffsetCollector;
use Hypervel\PHPStan\Collectors\WorkerStateParameterCollector;
use Hypervel\PHPStan\Collectors\WorkerStateWriteCollector;
use Hypervel\PHPStan\Rules\Coroutine\CoroutineRuleHelper;
use Hypervel\PHPStan\Rules\Coroutine\WorkerStateHelper;
use Hypervel\PHPStan\Rules\Coroutine\WorkerStateRule;
use Hypervel\PHPStan\Support\RuntimeInputs;
use Hypervel\Queue\Events\JobProcessed;
use Hypervel\Routing\Router;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerState\BaseRecordingController;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerState\BinderController;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerState\BoundFreshService;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerState\ClosureService;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerState\ConsumerService;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerState\ContextualConfigJob;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerState\ContextualDependency;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerState\ContextualImplementation;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerState\ContractImplementation;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerState\CountingMiddleware;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerState\FactoryBoundService;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerState\HelperController;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerState\LexicalChildController;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerState\LocalConfig;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerState\MappedCommand;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerState\MappedHandler;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerState\OtherConsumer;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerState\OverridingRecordingController;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerState\ReportController;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerState\ReportEvent;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerState\RepositoryPropertyController;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerState\ScopedBoundService;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerState\ScopedController;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerState\ScopedHelperController;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerState\SendReportListener;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerState\ServiceContract;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerState\SingletonBoundService;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerStateListeners\BaseRegistryListener;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerStateListeners\CatchAllObserver;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerStateListeners\ClonedEvent;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerStateListeners\ClonedListener;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerStateListeners\CollectingListener;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerStateListeners\JobListener;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerStateListeners\ObservingClonedListener;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerStateListeners\Post;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerStateListeners\ProviderObserver;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerStateListeners\QueuedListener;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerStateListeners\QueuedObserver;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerStateListeners\Registry;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerStateListeners\RegistryListener;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerStateListeners\RequestListener;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerStateListeners\SharedRegistry;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerStateListeners\WildcardJobListener;
use PhpParser\Node;
use PHPStan\Collectors\Collector;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_map;
use function array_merge;
use function sprintf;

/** @extends RuleTestCase<WorkerStateRule> */
class WorkerStateRuleTest extends RuleTestCase
{
    private const string NAMESPACE = 'Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerState\\';

    private const string SHARED_STATE = 'Property %1$s::$%2$s keeps %3$s, but the container shares %1$s for the whole worker.';

    private const string SHARED_STATE_TIP = 'Pass request values to the methods that use them or keep them in CoroutineContext. If the whole object belongs to one request, mark it #[Scoped].';

    private const string SHARED_WRITE = '%1$s::%2$s() writes $this->%3$s while handling a request, but the container shares %1$s across concurrent requests.';

    private const string SHARED_WRITE_TIP = 'Keep per-request values in local variables or CoroutineContext.';

    private const string STATIC_STATE = 'Static property %s::$%s keeps %s, which the whole worker then shares.';

    private const string STATIC_WRITE = 'Static property %s::$%s is written %s, but static properties are shared by the whole worker.';

    private const string STATIC_TIP = 'Keep per-request values in CoroutineContext, and set static state only during boot.';

    private const string CACHE = '%s %s::$%s is keyed by request or user data, so it can grow for the life of the worker.';

    private const string CACHE_TIP = 'Use a cache store with an expiry, or keep per-request values in CoroutineContext.';

    private const string CONFIG = 'Configuration is changed %s, which changes it for the whole worker.';

    private const string CONFIG_TIP = 'Set configuration during boot, and keep per-request values in CoroutineContext.';

    private const string REQUEST = 'while handling a request';

    private const string JOB = 'while running a job';

    private const string OPERATION = 'during an operation';

    private const string LISTENERS_NAMESPACE = 'Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerStateListeners\\';

    /**
     * The checkWorkerSharedState, checkStaticStateMutation, checkUnboundedWorkerCaches and
     * noRuntimeConfigMutation switches.
     *
     * @var array{bool, bool, bool, bool}
     */
    private array $switches = [true, true, true, true];

    protected function getRule(): Rule
    {
        $helper = $this->createHelper();
        $helper->setContainer($this->createApplication());

        return new WorkerStateRule($helper);
    }

    /** @return array<Collector<Node, mixed>> */
    protected function getCollectors(): array
    {
        $helper = $this->createHelper();

        return [
            new WorkerStateWriteCollector($helper),
            new WorkerStateCallCollector($this->createReflectionProvider(), $helper),
            new WorkerStateClassCollector($this->createReflectionProvider(), $helper, self::getContainer()->getByType(RuntimeInputs::class)),
            new WorkerStateParameterCollector($helper),
            new WorkerStateConfigOffsetCollector($helper),
        ];
    }

    /**
     * @param array{bool, bool, bool, bool} $switches
     * @param list<string> $groups
     */
    #[DataProvider('switchProvider')]
    public function testRule(array $switches, array $groups): void
    {
        $this->switches = $switches;
        $errors = self::getExpectedErrors();

        $this->analyse(
            [__DIR__ . '/data/worker-state.php'],
            array_merge([], ...array_map(static fn (string $group): array => $errors[$group], $groups)),
        );
    }

    /**
     * @return iterable<string, array{array{bool, bool, bool, bool}, list<string>}>
     */
    public static function switchProvider(): iterable
    {
        yield 'all checks' => [[true, true, true, true], ['shared', 'static', 'cache', 'config']];
        yield 'without shared state' => [[false, true, true, true], ['static', 'cache', 'config']];
        yield 'without static state' => [[true, false, true, true], ['shared', 'cache', 'config']];
        yield 'without unbounded caches' => [[true, true, false, true], ['shared', 'static', 'static cache write', 'config']];
        yield 'without config' => [[true, true, true, false], ['shared', 'static', 'cache']];
        yield 'no checks' => [[false, false, false, false], []];
    }

    public function testRouteClosures(): void
    {
        $static = [sprintf(self::STATIC_WRITE, self::NAMESPACE . 'CountingMiddleware', 'seen', self::REQUEST), self::STATIC_TIP];

        $this->analyse([__DIR__ . '/data/worker-state-routes.php'], [
            [$static[0], 13, $static[1]],
            [sprintf(self::CONFIG, self::REQUEST), 14, self::CONFIG_TIP],
            [$static[0], 22, $static[1]],
            [$static[0], 25, $static[1]],
            [$static[0], 29, $static[1]],
            [$static[0], 41, $static[1]],
            [sprintf(self::CONFIG, self::REQUEST), 47, self::CONFIG_TIP],
            [$static[0], 54, $static[1]],
            [sprintf(self::CONFIG, self::REQUEST), 62, self::CONFIG_TIP],
        ]);
    }

    public function testListenerAndObserverContexts(): void
    {
        $write = static fn (string $class, string $property, string $context, int $line): array => [
            sprintf(self::STATIC_WRITE, self::LISTENERS_NAMESPACE . $class, $property, $context),
            $line,
            self::STATIC_TIP,
        ];

        $jobWrite = static fn (string $class, string $method, string $property, int $line): array => [
            sprintf('%1$s::%2$s() writes $this->%3$s %4$s, but the container shares %1$s across concurrent requests.', self::LISTENERS_NAMESPACE . $class, $method, $property, self::JOB),
            $line,
            self::SHARED_WRITE_TIP,
        ];
        $request = static fn (string $class, string $property, int $line): array => [
            sprintf(self::SHARED_STATE, self::LISTENERS_NAMESPACE . $class, $property, 'the current request'),
            $line,
            self::SHARED_STATE_TIP,
        ];

        $this->analyse([__DIR__ . '/data/worker-state-listeners.php'], [
            $write('RequestListener', 'seen', self::REQUEST, 24),
            $write('JobListener', 'recorded', self::JOB, 37),
            $write('QueuedListener', 'recorded', self::JOB, 50),
            $write('ProviderObserver', 'created', self::OPERATION, 63),
            $write('DirectObserver', 'audited', self::OPERATION, 90),
            $write('RepeatedObserver', 'updated', self::OPERATION, 111),
            $write('BaseObserver', 'deleted', self::OPERATION, 134),
            $write('AuditObserver', 'archived', self::OPERATION, 147),
            $write('WildcardJobListener', 'seen', self::JOB, 185),
            $write('CatchAllObserver', 'observed', self::OPERATION, 198),
            $write('QueuedObserver', 'observed', self::REQUEST, 215),
            [sprintf(self::CONFIG, self::REQUEST), 216, self::CONFIG_TIP],
            $jobWrite('ClonedListener', 'failed', 'failures', 275),
            $request('ClonedListener', 'latest', 283),
            $request('CollectingListener', 'values', 313),
            $jobWrite('CollectingListener', 'handle', 'groups', 314),
            $jobWrite('CollectingListener', 'handle', 'totals', 315),
            $jobWrite('CollectingListener', 'count', 'values', 324),
            $request('Registry', 'request', 337),
            $request('BaseRegistry', 'request', 363),
            $request('ObservingClonedListener', 'request', 395),
        ]);
    }

    /**
     * Route actions at the same position of different files are different functions.
     */
    public function testRouteActionsAreKeyedByFile(): void
    {
        $this->analyse([__DIR__ . '/data/worker-state-route-reassigned.php', __DIR__ . '/data/worker-state-route-written.php'], [
            [sprintf(self::CONFIG, self::REQUEST), 11, self::CONFIG_TIP],
        ]);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-rules.neon'];
    }

    /**
     * Get the expected errors for worker-state.php, grouped by the switch that reports them.
     *
     * @return array<string, list<array{string, int, string}>>
     */
    private static function getExpectedErrors(): array
    {
        $sharedState = static fn (string $class, string $property, string $state, int $line): array => [
            sprintf(self::SHARED_STATE, self::NAMESPACE . $class, $property, $state),
            $line,
            self::SHARED_STATE_TIP,
        ];
        $sharedWrite = static fn (string $class, string $method, string $property, int $line): array => [
            sprintf(self::SHARED_WRITE, self::NAMESPACE . $class, $method, $property),
            $line,
            self::SHARED_WRITE_TIP,
        ];
        $staticWrite = static fn (string $class, string $property, string $context, int $line): array => [
            sprintf(self::STATIC_WRITE, self::NAMESPACE . $class, $property, $context),
            $line,
            self::STATIC_TIP,
        ];
        $cache = static fn (string $kind, string $class, string $property, int $line): array => [
            sprintf(self::CACHE, $kind, self::NAMESPACE . $class, $property),
            $line,
            self::CACHE_TIP,
        ];

        return [
            'shared' => [
                $sharedState('SharedService', 'request', 'the current request', 86),
                $sharedState('NestedService', 'user', 'a user', 140),
                $sharedState('SelfBuildingSingleton', 'request', 'the current request', 183),
                $sharedState('CurrentUserSingleton', 'user', 'a user', 210),
                $sharedState('CachedService', 'request', 'the current request', 234),
                $sharedState('SingletonBoundService', 'request', 'the current request', 278),
                $sharedState('ContractImplementation', 'request', 'the current request', 302),
                $sharedState('AttributeSingleton', 'request', 'the current request', 324),
                $sharedState('ReassignedUserService', 'user', 'a user', 368),
                $sharedState('CurrentUserCacheService', 'user', 'a user', 396),
                $sharedState('RequestCaptureService', 'request', 'the current request', 402),
                $sharedState('ContextualImplementation', 'request', 'the current request', 417),
                $sharedState('OtherConsumer', 'model', 'a user', 430),
                $sharedWrite('CountingMiddleware', 'handle', 'handled', 447),
                $sharedWrite('ReportController', 'show', 'shown', 465),
                $sharedState('ReportService', 'request', 'the current request', 506),
                $sharedState('RoutableService', 'request', 'the current request', 516),
                $sharedState('ClosureService', 'request', 'the current request', 578),
                $sharedState('JobService', 'request', 'the current request', 643),
                $sharedState('SendReportListener', 'request', 'the current request', 660),
                $sharedState('MappedHandler', 'request', 'the current request', 710),
                $sharedWrite('HelperController', 'track', 'tracked', 750),
                $sharedWrite('OverridingRecordingController', 'record', 'overridden', 812),
                $sharedWrite('LexicalParentController', 'resolve', 'resolved', 830),
                $sharedWrite('RepositoryPropertyController', 'update', 'settings', 914),
            ],
            'static' => [
                [sprintf(self::STATIC_STATE, self::NAMESPACE . 'SharedService', 'current', 'the current request'), 87, self::STATIC_TIP],
                $staticWrite('CountingMiddleware', 'seen', self::REQUEST, 448),
                $staticWrite('StoreReportRequest', 'validated', self::REQUEST, 588),
                $staticWrite('ProcessReport', 'runs', self::JOB, 603),
                $staticWrite('RebuildReport', 'rebuilt', self::JOB, 857),
                $staticWrite('RepositoryPropertyController', 'defaults', self::REQUEST, 913),
            ],
            'cache' => [
                $cache('Property', 'SharedService', 'visits', 88),
                $cache('Property', 'SharedService', 'visits', 89),
                $cache('Property', 'SharedService', 'visits', 90),
                $cache('Static property', 'SharedService', 'memo', 95),
                $cache('Static property', 'CountingMiddleware', 'clients', 449),
            ],
            // Without the cache check, the request-keyed static write in middleware is still a request-time write.
            'static cache write' => [
                $staticWrite('CountingMiddleware', 'clients', self::REQUEST, 449),
            ],
            'config' => [
                [sprintf(self::CONFIG, self::REQUEST), 450, self::CONFIG_TIP],
                [sprintf(self::CONFIG, self::REQUEST), 466, self::CONFIG_TIP],
                [sprintf(self::CONFIG, self::REQUEST), 467, self::CONFIG_TIP],
                [sprintf(self::CONFIG, self::REQUEST), 468, self::CONFIG_TIP],
                [sprintf(self::CONFIG, self::REQUEST), 469, self::CONFIG_TIP],
                [sprintf(self::CONFIG, self::REQUEST), 470, self::CONFIG_TIP],
                [sprintf(self::CONFIG, self::JOB), 604, self::CONFIG_TIP],
                [sprintf(self::CONFIG, self::JOB), 618, self::CONFIG_TIP],
                [sprintf(self::CONFIG, self::JOB), 622, self::CONFIG_TIP],
                [sprintf(self::CONFIG, self::JOB), 623, self::CONFIG_TIP],
                [sprintf(self::CONFIG, self::REQUEST), 751, self::CONFIG_TIP],
                [sprintf(self::CONFIG, self::JOB), 858, self::CONFIG_TIP],
                [sprintf(self::CONFIG, self::JOB), 869, self::CONFIG_TIP],
                [sprintf(self::CONFIG, self::JOB), 871, self::CONFIG_TIP],
                [sprintf(self::CONFIG, self::JOB), 883, self::CONFIG_TIP],
                [sprintf(self::CONFIG, self::JOB), 884, self::CONFIG_TIP],
                [sprintf(self::CONFIG, self::JOB), 886, self::CONFIG_TIP],
                [sprintf(self::CONFIG, self::JOB), 887, self::CONFIG_TIP],
                [sprintf(self::CONFIG, self::JOB), 890, self::CONFIG_TIP],
            ],
        ];
    }

    /**
     * Create the helper with the test's switches.
     */
    private function createHelper(): WorkerStateHelper
    {
        return new WorkerStateHelper(
            $this->createReflectionProvider(),
            self::getContainer()->getByType(CoroutineRuleHelper::class),
            self::getContainer()->getByType(RuntimeInputs::class),
            ...$this->switches,
            checkRestrictedCalls: false,
            checkCancellationHandling: false,
        );
    }

    /**
     * Create the booted application the lifetimes are read from, apart from the shared analyzer application.
     */
    private function createApplication(): Container
    {
        $container = new Container;
        $container->instance('config', new ConfigRepository);
        $container->alias('config', ConfigRepository::class);
        $container->alias('config', Repository::class);
        $container->alias('config', 'settings');
        $events = new Dispatcher($container);
        $router = new Router($events, $container);
        $bus = new BusDispatcher($container);

        $container->instance('events', $events);
        $container->instance('router', $router);
        $container->instance(BusDispatcherContract::class, $bus);

        $router->aliasMiddleware('counting', CountingMiddleware::class);
        $router->get('/reports/{report}/{bound_routable}', [ReportController::class, 'show'])->middleware('counting');
        $router->get('/scoped', [ScopedController::class, 'index']);
        $router->get('/binders/{thing}', [BinderController::class, 'show']);
        $router->bind('thing', static fn (string $value): string => $value);
        $router->get('/closure', static fn (ClosureService $service): string => '');
        $router->get('/helpers', [HelperController::class, 'index']);
        $router->get('/scoped-helpers', [ScopedHelperController::class, 'index']);
        $router->get('/records', [BaseRecordingController::class, 'index']);
        $router->get('/lexical', [LexicalChildController::class, 'index']);
        $router->get('/settings', [RepositoryPropertyController::class, 'update']);

        $events->listen(ReportEvent::class, SendReportListener::class);
        $events->listen(RequestReceived::class, RequestListener::class);
        $events->listen(JobProcessed::class, JobListener::class . '@record');
        $events->listen(RequestHandled::class, [QueuedListener::class, 'record']);
        $events->listen('eloquent.created: ' . Post::class, ProviderObserver::class . '@created');
        $events->listen('Hypervel\Queue\Events\Job*', WildcardJobListener::class);
        $events->listen('*', CatchAllObserver::class . '@observe');
        $events->observe(RequestReceived::class, QueuedObserver::class);
        $events->listen(ClonedEvent::class, ClonedListener::class);
        $events->listen(ClonedEvent::class, CollectingListener::class);
        $events->listen(ClonedEvent::class, RegistryListener::class);
        $events->listen(ClonedEvent::class, BaseRegistryListener::class);
        $events->listen(ClonedEvent::class, ObservingClonedListener::class);
        // An application event has no known operation, but the observer still runs on the shared instance.
        $events->observe('listeners.audited', ObservingClonedListener::class);
        $bus->map([MappedCommand::class => MappedHandler::class]);

        $container->bind(BoundFreshService::class);
        $container->singleton(SingletonBoundService::class);
        $container->scoped(ScopedBoundService::class);
        $container->singleton(FactoryBoundService::class, static fn (): FactoryBoundService => new FactoryBoundService);
        $container->singleton(ServiceContract::class, ContractImplementation::class);
        $container->singleton(BaseRecordingController::class, OverridingRecordingController::class);
        $container->bind(Authenticatable::class, User::class);
        $container->when(ConsumerService::class)->needs(ContextualDependency::class)->give(ContextualImplementation::class);
        $container->when(OtherConsumer::class)->needs(User::class)->give(static fn (): User => new User);
        $container->when(ContextualConfigJob::class)->needs(Repository::class)->give(LocalConfig::class);
        // Shared on their own as well as through the cloned listeners that extend them.
        $container->singleton(Registry::class);
        $container->singleton(SharedRegistry::class);

        return $container;
    }
}
