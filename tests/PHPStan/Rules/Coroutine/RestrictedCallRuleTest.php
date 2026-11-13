<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Coroutine;

use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Core\Events\OnReceive;
use Hypervel\Events\Dispatcher;
use Hypervel\Http\Client\Factory;
use Hypervel\HttpServer\Events\RequestHandled;
use Hypervel\PHPStan\Collectors\RestrictedCallCollector;
use Hypervel\PHPStan\Collectors\WorkerStateCallCollector;
use Hypervel\PHPStan\Collectors\WorkerStateClassCollector;
use Hypervel\PHPStan\Collectors\WorkerStateConfigOffsetCollector;
use Hypervel\PHPStan\Collectors\WorkerStateGlobalWriteCollector;
use Hypervel\PHPStan\Collectors\WorkerStateParameterCollector;
use Hypervel\PHPStan\Collectors\WorkerStateWriteCollector;
use Hypervel\PHPStan\Rules\Coroutine\CoroutineRuleHelper;
use Hypervel\PHPStan\Rules\Coroutine\RestrictedCallRule;
use Hypervel\PHPStan\Rules\Coroutine\WorkerStateHelper;
use Hypervel\PHPStan\Rules\Coroutine\WorkerStateRule;
use Hypervel\PHPStan\Support\FacadeHelper;
use Hypervel\PHPStan\Support\RuntimeInputs;
use Hypervel\Routing\Router;
use Hypervel\Support\DateFactory;
use Hypervel\Tests\PHPStan\Rules\Coroutine\RestrictedCalls\ContextualConfigController;
use Hypervel\Tests\PHPStan\Rules\Coroutine\RestrictedCalls\ContextualRegistry;
use Hypervel\Tests\PHPStan\Rules\Coroutine\RestrictedCalls\FactoryRegistry;
use Hypervel\Tests\PHPStan\Rules\Coroutine\RestrictedCalls\FreshRegistry;
use Hypervel\Tests\PHPStan\Rules\Coroutine\RestrictedCalls\FreshRegistryJob;
use Hypervel\Tests\PHPStan\Rules\Coroutine\RestrictedCalls\InheritedRegistryListener;
use Hypervel\Tests\PHPStan\Rules\Coroutine\RestrictedCalls\OptionRegistryListener;
use Hypervel\Tests\PHPStan\Rules\Coroutine\RestrictedCalls\QueuedRegistryListener;
use Hypervel\Tests\PHPStan\Rules\Coroutine\RestrictedCalls\ReceiveListener;
use Hypervel\Tests\PHPStan\Rules\Coroutine\RestrictedCalls\ReceiverController;
use Hypervel\Tests\PHPStan\Rules\Coroutine\RestrictedCalls\Registry;
use Hypervel\Tests\PHPStan\Rules\Coroutine\RestrictedCalls\ReportController;
use Hypervel\Tests\PHPStan\Rules\Coroutine\RestrictedCalls\UnknownRegistryTask;
use PhpParser\Node;
use PHPStan\Collectors\Collector;
use PHPStan\Rules\Rule;
use PHPStan\Testing\CompositeRule;
use PHPStan\Testing\RuleTestCase;

use function sprintf;

/** @extends RuleTestCase<CompositeRule> */
class RestrictedCallRuleTest extends RuleTestCase
{
    public const string MESSAGE = 'Call to #[%s] method %s()%s %s.';

    public const string BOOT_TIP = "Call it while the application boots, such as in a service provider's boot() method, and keep per-request state in CoroutineContext or a scoped() binding.";

    private const string TESTS_TIP = 'Call it only from tests.';

    private const string BOOT_OR_TESTS_TIP = "Call it while the application boots, such as in a service provider's boot() method, or in a test, and keep per-request state in CoroutineContext or a scoped() binding.";

    private const string SHARED_TIP = ' Or call it on an instance of your own.';

    private const string SHARED = ' on the worker-shared instance';

    private const string REQUEST = 'while handling a request';

    private const string JOB = 'while running a job';

    private ?WorkerStateHelper $helper = null;

    private ?FacadeHelper $facadeHelper = null;

    protected function getRule(): Rule
    {
        // Both rules, so a configuration write gets exactly one diagnostic.
        return new CompositeRule([new WorkerStateRule($this->getHelper()), new RestrictedCallRule($this->getHelper())]);
    }

    /** @return array<Collector<Node, mixed>> */
    protected function getCollectors(): array
    {
        $helper = $this->getHelper();

        return [
            new RestrictedCallCollector($this->createReflectionProvider(), $helper, $this->getFacadeHelper()),
            new WorkerStateWriteCollector($helper),
            new WorkerStateCallCollector($this->createReflectionProvider(), $helper),
            new WorkerStateClassCollector($this->createReflectionProvider(), $helper, self::getContainer()->getByType(RuntimeInputs::class)),
            new WorkerStateParameterCollector($helper),
            new WorkerStateConfigOffsetCollector($helper),
            new WorkerStateGlobalWriteCollector($helper),
        ];
    }

    public function testRule(): void
    {
        $registry = Registry::class;
        $boot = static fn (string $method, string $condition, int $line, string $context = self::REQUEST): array => [
            sprintf(self::MESSAGE, 'BootOnly', $registry . '::' . $method, $condition, $context),
            $line,
            self::BOOT_TIP,
        ];
        $fake = [sprintf(self::MESSAGE, 'TestsOnly', $registry . '::fake', '', self::REQUEST), self::TESTS_TIP];
        $configure = static fn (int $line, string $context = self::REQUEST): array => [
            sprintf(self::MESSAGE, 'BootOrTestsOnly', $registry . '::configure', self::SHARED, $context),
            $line,
            self::BOOT_OR_TESTS_TIP . self::SHARED_TIP,
        ];

        $config = static fn (int $line): array => [
            'Configuration is changed while handling a request, which changes it for the whole worker.',
            $line,
            'Set configuration during boot, and keep per-request values in CoroutineContext.',
        ];

        $this->analyse([__DIR__ . '/data/restricted-calls.php'], [
            $boot('register', '', 86),
            [$fake[0], 87, $fake[1]],
            [$fake[0], 88, $fake[1]],
            $configure(92),
            $configure(94),
            $configure(97),
            $configure(98),
            [sprintf(self::MESSAGE, 'BootOnly', Factory::class . '::globalOptions', self::SHARED, self::REQUEST), 116, self::BOOT_TIP . self::SHARED_TIP],
            [sprintf(self::MESSAGE, 'BootOnly', DateFactory::class . '::useClass', '', self::REQUEST), 117, self::BOOT_TIP],
            $config(118),
            [sprintf(self::MESSAGE, 'BootOnly', ConfigRepository::class . '::macro', '', self::REQUEST), 119, self::BOOT_TIP],
            $boot('map', ' with a non-default $map', 126),
            $boot('map', ' with a non-default $map', 127),
            $boot('map', ' possibly with a non-default $map', 128),
            $boot('map', ' possibly with a non-default $map', 129),
            $boot('remember', ' with $duration', 131),
            $boot('remember', ' with $duration', 132),
            $boot('remember', ' possibly with $duration', 134),
            $configure(148, self::JOB),
            $boot('register', '', 157, 'during an operation'),
            $boot('register', '', 170),
            $configure(204),
            $configure(207),
            $config(210),
            $config(211),
            $configure(251, self::JOB),
            $configure(299, self::JOB),
            $configure(310, self::JOB),
            $configure(331, self::JOB),
        ]);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-rules.neon'];
    }

    /**
     * Get the helper the rules and collectors share, reading the test's booted application.
     */
    private function getHelper(): WorkerStateHelper
    {
        if ($this->helper === null) {
            $this->helper = new WorkerStateHelper(
                $this->createReflectionProvider(),
                self::getContainer()->getByType(CoroutineRuleHelper::class),
                self::getContainer()->getByType(RuntimeInputs::class),
                checkWorkerSharedState: true,
                checkStaticStateMutation: true,
                checkUnboundedWorkerCaches: true,
                noRuntimeConfigMutation: true,
                checkRestrictedCalls: true,
                checkCancellationHandling: false,
            );

            $this->helper->setContainer($this->createApplication());
        }

        return $this->helper;
    }

    /**
     * Get the facade helper reading the test's booted application.
     */
    private function getFacadeHelper(): FacadeHelper
    {
        if ($this->facadeHelper === null) {
            $this->facadeHelper = new FacadeHelper(
                $this->createReflectionProvider(),
                self::getContainer()->getService('cachedRichParser'),
                self::getContainer()->getByType(RuntimeInputs::class),
            );

            $this->facadeHelper->setContainer($this->getHelper()->getContainer());
        }

        return $this->facadeHelper;
    }

    /**
     * Create the booted application the lifetimes and facade roots are read from.
     */
    private function createApplication(): Container
    {
        $container = new Container;
        $container->instance('config', new ConfigRepository);
        $container->alias('config', ConfigRepository::class);
        $container->alias('config', Repository::class);
        $events = new Dispatcher($container);
        $router = new Router($events, $container);

        $container->instance('events', $events);
        $container->instance('router', $router);

        $router->get('/reports', [ReportController::class, 'index']);
        $router->get('/reports/update', [ReportController::class, 'update']);
        $router->get('/framework', [ReportController::class, 'framework']);
        $events->listen(OnReceive::class, ReceiveListener::class);

        // Another route running the same action through a route binder leaves its injection unknown.
        $router->bind('report', static fn (string $value): Registry => new Registry);
        $router->get('/receivers', [ReceiverController::class, 'index']);
        $router->get('/receivers/replace', [ReceiverController::class, 'replace']);
        $router->get('/receivers/{report}', [ReceiverController::class, 'show']);
        $router->get('/receivers/show', [ReceiverController::class, 'show']);
        $router->get('/config', [ContextualConfigController::class, 'update']);
        $events->listen('restricted.recorded', QueuedRegistryListener::class . '@record');
        $events->listen('restricted.inherited', InheritedRegistryListener::class . '@record');
        $events->listen(RequestHandled::class, OptionRegistryListener::class);

        $container->singleton('restricted.registry', static fn (): Registry => new Registry);
        $container->singleton('restricted.unknown', static function (): Registry {
            $registry = new Registry;

            return $registry;
        });
        $container->bind(FreshRegistry::class);
        $container->singleton(FactoryRegistry::class, static fn (): FactoryRegistry => new FactoryRegistry);
        $container->singleton(ContextualRegistry::class, static fn (): ContextualRegistry => new ContextualRegistry);
        $container->when(ReceiverController::class)->needs(Registry::class)->give(FreshRegistry::class);
        $container->when(ReceiverController::class)->needs(ContextualRegistry::class)->give(static fn (): ContextualRegistry => new ContextualRegistry);
        $container->when(ContextualConfigController::class)->needs(Repository::class)->give(static fn (): ConfigRepository => new ConfigRepository);

        // Each pair of jobs shares one handler, listed in the order where keeping one job's injection would report.
        $container->when(FreshRegistryJob::class)->needs(Registry::class)->give(FreshRegistry::class);
        $container->when(UnknownRegistryTask::class)->needs(Registry::class)->give(static fn (): Registry => new Registry);

        return $container;
    }
}
