<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Coroutine;

use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Container\Container;
use Hypervel\Events\Dispatcher;
use Hypervel\PHPStan\Collectors\RestrictedCallCollector;
use Hypervel\PHPStan\Collectors\WorkerStateCallCollector;
use Hypervel\PHPStan\Collectors\WorkerStateClassCollector;
use Hypervel\PHPStan\Collectors\WorkerStateConfigOffsetCollector;
use Hypervel\PHPStan\Collectors\WorkerStateParameterCollector;
use Hypervel\PHPStan\Collectors\WorkerStateWriteCollector;
use Hypervel\PHPStan\Rules\Coroutine\RestrictedCallRule;
use Hypervel\PHPStan\Rules\Coroutine\WorkerStateHelper;
use Hypervel\PHPStan\Rules\Coroutine\WorkerStateRule;
use Hypervel\PHPStan\Support\FacadeHelper;
use Hypervel\Routing\Router;
use Hypervel\Tests\PHPStan\Rules\Coroutine\RestrictedCallsOnly\Registry;
use Hypervel\Tests\PHPStan\Rules\Coroutine\RestrictedCallsOnly\SettingsController;
use PhpParser\Node;
use PHPStan\Collectors\Collector;
use PHPStan\Rules\Rule;
use PHPStan\Testing\CompositeRule;
use PHPStan\Testing\RuleTestCase;

use function sprintf;

/**
 * The shared worker-state model is still collected when restricted calls are the only check that
 * needs it.
 *
 * @extends RuleTestCase<CompositeRule>
 */
class RestrictedCallsOnlyTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        $application = $this->createApplication();

        self::getContainer()->getByType(WorkerStateHelper::class)->setContainer($application);
        self::getContainer()->getByType(FacadeHelper::class)->setContainer($application);

        return new CompositeRule([
            self::getContainer()->getByType(WorkerStateRule::class),
            self::getContainer()->getByType(RestrictedCallRule::class),
        ]);
    }

    /** @return array<Collector<Node, mixed>> */
    protected function getCollectors(): array
    {
        return [
            self::getContainer()->getByType(RestrictedCallCollector::class),
            self::getContainer()->getByType(WorkerStateWriteCollector::class),
            self::getContainer()->getByType(WorkerStateCallCollector::class),
            self::getContainer()->getByType(WorkerStateClassCollector::class),
            self::getContainer()->getByType(WorkerStateParameterCollector::class),
            self::getContainer()->getByType(WorkerStateConfigOffsetCollector::class),
        ];
    }

    public function testHelperCallsAreReportedWithoutStateChecks(): void
    {
        $this->analyse([__DIR__ . '/data/restricted-calls-only.php'], [
            [
                sprintf(RestrictedCallRuleTest::MESSAGE, 'BootOnly', Registry::class . '::register', '', 'while handling a request'),
                27,
                RestrictedCallRuleTest::BOOT_TIP,
            ],
            [
                sprintf(RestrictedCallRuleTest::MESSAGE, 'BootOnly', ConfigRepository::class . '::macro', '', 'while handling a request'),
                29,
                RestrictedCallRuleTest::BOOT_TIP,
            ],
        ]);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/data/restricted-calls-only.neon'];
    }

    /**
     * Create the booted application the lifetimes and facade roots are read from.
     */
    private function createApplication(): Container
    {
        $container = new Container;
        $container->instance('config', new ConfigRepository);
        $events = new Dispatcher($container);
        $router = new Router($events, $container);

        $container->instance('events', $events);
        $container->instance('router', $router);

        $router->post('/settings', [SettingsController::class, 'update']);

        return $container;
    }
}
