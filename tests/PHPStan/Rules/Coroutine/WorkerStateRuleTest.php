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
use Hypervel\PHPStan\Collectors\WorkerStateCallCollector;
use Hypervel\PHPStan\Collectors\WorkerStateClassCollector;
use Hypervel\PHPStan\Collectors\WorkerStateConfigOffsetCollector;
use Hypervel\PHPStan\Collectors\WorkerStateParameterCollector;
use Hypervel\PHPStan\Collectors\WorkerStateWriteCollector;
use Hypervel\PHPStan\Rules\Coroutine\CoroutineRuleHelper;
use Hypervel\PHPStan\Rules\Coroutine\WorkerStateHelper;
use Hypervel\PHPStan\Rules\Coroutine\WorkerStateRule;
use Hypervel\Routing\Router;
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
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerState\LocalConfig;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerState\MappedCommand;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerState\MappedHandler;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerState\OtherConsumer;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerState\ReportController;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerState\ReportEvent;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerState\ScopedBoundService;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerState\ScopedController;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerState\SendReportListener;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerState\ServiceContract;
use Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerState\SingletonBoundService;
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

    /**
     * The checkWorkerSharedState, checkStaticStateMutation, checkUnboundedWorkerCaches and
     * noRuntimeConfigMutation switches.
     *
     * @var array{bool, bool, bool, bool}
     */
    private array $switches = [true, true, true, true];

    protected function getRule(): Rule
    {
        $rule = new WorkerStateRule($this->createReflectionProvider(), $this->createHelper());
        $rule->setContainer($this->createApplication());

        return $rule;
    }

    /** @return array<Collector<Node, mixed>> */
    protected function getCollectors(): array
    {
        $helper = $this->createHelper();

        return [
            new WorkerStateWriteCollector($helper),
            new WorkerStateCallCollector($this->createReflectionProvider(), $helper),
            new WorkerStateClassCollector($helper),
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
                $sharedState('SharedService', 'request', 'the current request', 84),
                $sharedState('NestedService', 'user', 'a user', 127),
                $sharedState('SelfBuildingSingleton', 'request', 'the current request', 170),
                $sharedState('CurrentUserSingleton', 'user', 'a user', 197),
                $sharedState('CachedService', 'request', 'the current request', 221),
                $sharedState('SingletonBoundService', 'request', 'the current request', 265),
                $sharedState('ContractImplementation', 'request', 'the current request', 289),
                $sharedState('AttributeSingleton', 'request', 'the current request', 311),
                $sharedState('ReassignedUserService', 'user', 'a user', 355),
                $sharedState('CurrentUserCacheService', 'user', 'a user', 383),
                $sharedState('RequestCaptureService', 'request', 'the current request', 389),
                $sharedState('ContextualImplementation', 'request', 'the current request', 404),
                $sharedState('OtherConsumer', 'model', 'a user', 417),
                $sharedWrite('CountingMiddleware', 'handle', 'handled', 434),
                $sharedWrite('ReportController', 'show', 'shown', 452),
                $sharedState('ReportService', 'request', 'the current request', 493),
                $sharedState('RoutableService', 'request', 'the current request', 503),
                $sharedState('ClosureService', 'request', 'the current request', 565),
                $sharedState('JobService', 'request', 'the current request', 630),
                $sharedState('SendReportListener', 'request', 'the current request', 647),
                $sharedState('MappedHandler', 'request', 'the current request', 697),
            ],
            'static' => [
                [sprintf(self::STATIC_STATE, self::NAMESPACE . 'SharedService', 'current', 'the current request'), 85, self::STATIC_TIP],
                $staticWrite('CountingMiddleware', 'seen', self::REQUEST, 435),
                $staticWrite('StoreReportRequest', 'validated', self::REQUEST, 575),
                $staticWrite('ProcessReport', 'runs', self::JOB, 590),
            ],
            'cache' => [
                $cache('Property', 'SharedService', 'visits', 86),
                $cache('Property', 'SharedService', 'visits', 87),
                $cache('Property', 'SharedService', 'visits', 88),
                $cache('Static property', 'SharedService', 'memo', 93),
                $cache('Static property', 'CountingMiddleware', 'clients', 436),
            ],
            // Without the cache check, the request-keyed static write in middleware is still a request-time write.
            'static cache write' => [
                $staticWrite('CountingMiddleware', 'clients', self::REQUEST, 436),
            ],
            'config' => [
                [sprintf(self::CONFIG, self::REQUEST), 437, self::CONFIG_TIP],
                [sprintf(self::CONFIG, self::REQUEST), 453, self::CONFIG_TIP],
                [sprintf(self::CONFIG, self::REQUEST), 454, self::CONFIG_TIP],
                [sprintf(self::CONFIG, self::REQUEST), 455, self::CONFIG_TIP],
                [sprintf(self::CONFIG, self::REQUEST), 456, self::CONFIG_TIP],
                [sprintf(self::CONFIG, self::REQUEST), 457, self::CONFIG_TIP],
                [sprintf(self::CONFIG, self::JOB), 591, self::CONFIG_TIP],
                [sprintf(self::CONFIG, self::JOB), 605, self::CONFIG_TIP],
                [sprintf(self::CONFIG, self::JOB), 609, self::CONFIG_TIP],
                [sprintf(self::CONFIG, self::JOB), 610, self::CONFIG_TIP],
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
            ...$this->switches,
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

        $events->listen(ReportEvent::class, SendReportListener::class);
        $bus->map([MappedCommand::class => MappedHandler::class]);

        $container->bind(BoundFreshService::class);
        $container->singleton(SingletonBoundService::class);
        $container->scoped(ScopedBoundService::class);
        $container->singleton(FactoryBoundService::class, static fn (): FactoryBoundService => new FactoryBoundService);
        $container->singleton(ServiceContract::class, ContractImplementation::class);
        $container->bind(Authenticatable::class, User::class);
        $container->when(ConsumerService::class)->needs(ContextualDependency::class)->give(ContextualImplementation::class);
        $container->when(OtherConsumer::class)->needs(User::class)->give(static fn (): User => new User);
        $container->when(ContextualConfigJob::class)->needs(Repository::class)->give(LocalConfig::class);

        return $container;
    }
}
