<?php

declare(strict_types=1);

namespace Hypervel\Testing\PHPUnit;

use GuzzleHttp\Promise\TaskQueue;
use GuzzleHttp\Promise\Utils;
use Hypervel\Di\Aop\AspectCollector;
use Hypervel\Di\Aop\AstVisitorRegistry;
use Hypervel\Di\Bootstrap\GenerateProxies;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Http\Client\Guzzle\Aspects\PromiseConstructionAspect;
use Hypervel\Http\Client\Guzzle\Aspects\PromiseOperationAspect;
use Hypervel\Http\Client\Guzzle\Aspects\TransportOwnershipAspect;
use Hypervel\Http\Client\Guzzle\CoroutineTaskQueue;
use Hypervel\Sentry\Aspects\GuzzleHttpClientAspect as SentryGuzzleHttpClientAspect;
use Hypervel\Telescope\Aspects\GuzzleHttpClientAspect as TelescopeGuzzleHttpClientAspect;
use Hypervel\Testing\ParallelTesting;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

class AfterEachTestExtension implements Extension
{
    /**
     * Bootstrap the extension.
     */
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        Utils::queue(new TaskQueue(false));
        CoroutineTaskQueue::install();
        $this->generateGuzzleProxies();

        TestStateRegistrars::forRootInstall()->register();

        $cleanup = new AfterEachTestSubscriber;

        $facade->registerSubscriber(new AfterEachTestPreparationStartedSubscriber($cleanup));
        $facade->registerSubscriber($cleanup);
        $facade->registerSubscriber(new AfterEachTestExecutionFinishedSubscriber($cleanup));
    }

    /**
     * Generate installed framework proxies before test discovery loads their targets.
     */
    protected function generateGuzzleProxies(): void
    {
        $existing = AspectCollector::getRules();
        $visitorsWereEmpty = AstVisitorRegistry::getQueue()->isEmpty();
        $directory = ParallelTesting::tempDir('guzzle-proxies');

        try {
            foreach ([
                PromiseConstructionAspect::class,
                PromiseOperationAspect::class,
                TransportOwnershipAspect::class,
                SentryGuzzleHttpClientAspect::class,
                TelescopeGuzzleHttpClientAspect::class,
            ] as $aspect) {
                if (! class_exists($aspect)) {
                    continue;
                }

                AspectCollector::register($aspect);
            }

            (new GenerateProxies)->generate($directory);
        } finally {
            // Proxy generation must not enable optional instrumentation in tests.
            AspectCollector::flushState();
            foreach ($existing as $aspect => $rule) {
                AspectCollector::setAround($aspect, $rule['classes'], $rule['priority']);
            }
            if ($visitorsWereEmpty) {
                AstVisitorRegistry::flushState();
            }
        }

        register_shutdown_function(static function () use ($directory): void {
            (new Filesystem)->deleteDirectory($directory);
        });
    }
}
