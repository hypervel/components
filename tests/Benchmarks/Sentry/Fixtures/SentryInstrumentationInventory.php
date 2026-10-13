<?php

declare(strict_types=1);

namespace Hypervel\Tests\Benchmarks\Sentry\Fixtures;

use Closure;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Http\Kernel as HttpKernel;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Di\Aop\AspectCollector;
use Hypervel\Redis\RedisConfig;
use Hypervel\Redis\RedisManager;
use Hypervel\Routing\Contracts\CallableDispatcher;
use Hypervel\Routing\Contracts\ControllerDispatcher;
use Hypervel\Sentry\Aspects\GuzzleHttpClientAspect;
use Hypervel\Sentry\SentryServiceProvider;
use Hypervel\Sentry\Tracing\ViewEngineDecorator;
use Hypervel\Tests\Sentry\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionFunction;
use ReflectionProperty;

// The baseline's Sentry test base has the same helpers under its old name.
if (! class_exists(TestCase::class)) {
    class_alias(\Hypervel\Tests\Sentry\SentryTestCase::class, TestCase::class);
}

/**
 * Record what Sentry registers in each configuration, without timing it.
 *
 * Run from either checkout: INVENTORY_OUT=/path/out.jsonl ./vendor/bin/phpunit --no-progress <this file>
 */
class SentryInstrumentationInventory extends TestCase
{
    private const array BREADCRUMB_TOGGLES = [
        'logs', 'cache', 'storage', 'sql_queries', 'sql_bindings', 'sql_transactions',
        'queue_info', 'command_info', 'http_client_requests', 'notifications',
    ];

    private const array TRACING_TOGGLES = [
        'queue_job_transactions', 'queue_jobs', 'sql_queries', 'sql_bindings', 'sql_origin', 'views',
        'http_client_requests', 'http_client_requests_origin', 'cache', 'storage', 'redis_origin',
        'notifications', 'missing_routes', 'continue_after_response',
    ];

    /**
     * Configure the application before providers boot.
     */
    protected function setUp(): void
    {
        $this->setupConfig = $this->providedData()[0];

        parent::setUp();
    }

    /**
     * Expand toggle overrides into complete breadcrumb and tracing sections.
     *
     * Package config merges only top-level keys, and the baseline reads some nested keys without defaults,
     * so a lone nested override would drop the other shipped values there.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        $shipped = require dirname((new ReflectionClass(SentryServiceProvider::class))->getFileName()) . '/../config/sentry.php';
        $config = ['sentry.breadcrumbs' => $shipped['breadcrumbs'], 'sentry.tracing' => $shipped['tracing']];

        foreach ($this->setupConfig as $key => $value) {
            if (preg_match('/^sentry\.(breadcrumbs|tracing)\.(.+)$/', $key, $matches)) {
                $config["sentry.{$matches[1]}"][$matches[2]] = $value;
            } else {
                $config[$key] = $value;
            }
        }

        $this->setupConfig = $config;

        return parent::getPackageProviders($app);
    }

    #[DataProvider('configurations')]
    public function testInventory(array $config): void
    {
        $listeners = [];
        foreach ($this->app->make('events')->getRawListeners() as $event => $eventListeners) {
            $count = count(array_filter($eventListeners, $this->isSentryListener(...)));
            if ($count > 0) {
                $listeners[$event] = $count;
            }
        }
        ksort($listeners);

        $redisConfig = (new ReflectionProperty(RedisManager::class, 'config'))->getValue($this->app->make(RedisManager::class));

        $inventory = [
            'name' => $this->dataName(),
            'listeners' => $listeners,
            'middleware' => array_values(array_filter(
                $this->app->make(HttpKernel::class)->getGlobalMiddleware(),
                static fn (mixed $middleware): bool => is_string($middleware) && str_starts_with($middleware, 'Hypervel\Sentry\\'),
            )),
            'guzzle_aspect' => AspectCollector::getRule(GuzzleHttpClientAspect::class) !== [],
            'after_created_callbacks' => count((new ReflectionProperty(Coroutine::class, 'afterCreatedCallbacks'))->getValue()),
            'callable_dispatcher' => $this->app->make(CallableDispatcher::class)::class,
            'controller_dispatcher' => $this->app->make(ControllerDispatcher::class)::class,
            'blade_engine_decorated' => $this->app->make('view.engine.resolver')->resolve('blade') instanceof ViewEngineDecorator,
            'redis_events_override' => (new ReflectionProperty(RedisConfig::class, 'eventsOverride'))->getValue($redisConfig),
        ];

        file_put_contents(getenv('INVENTORY_OUT'), json_encode($inventory, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

        if (array_key_exists('sentry.dsn', $config) && $config['sentry.dsn'] === null) {
            $this->assertSame([], $inventory['listeners']);
            $this->assertSame([], $inventory['middleware']);
            $this->assertFalse($inventory['guzzle_aspect']);
            $this->assertSame(0, $inventory['after_created_callbacks']);
            $this->assertFalse($inventory['blade_engine_decorated']);
        }
    }

    /**
     * Provide complete configurations and individual disabled toggles.
     */
    public static function configurations(): iterable
    {
        $sampled = ['sentry.traces_sample_rate' => 1.0];

        yield 'disabled' => [['sentry.dsn' => null]];
        yield 'errors-only' => [[]];
        yield 'unsampled' => [['sentry.traces_sample_rate' => 0.0]];
        yield 'sampled' => [$sampled];

        $allOff = $sampled;
        foreach (self::BREADCRUMB_TOGGLES as $toggle) {
            $allOff["sentry.breadcrumbs.{$toggle}"] = false;
        }
        foreach (self::TRACING_TOGGLES as $toggle) {
            $allOff["sentry.tracing.{$toggle}"] = false;
        }
        yield 'sampled, all toggles off' => [$allOff];

        foreach (self::BREADCRUMB_TOGGLES as $toggle) {
            yield "sampled, breadcrumbs.{$toggle} off" => [[...$sampled, "sentry.breadcrumbs.{$toggle}" => false]];
        }
        foreach (self::TRACING_TOGGLES as $toggle) {
            yield "sampled, tracing.{$toggle} off" => [[...$sampled, "sentry.tracing.{$toggle}" => false]];
        }
        yield 'sampled, tracing.redis_commands on' => [[...$sampled, 'sentry.tracing.redis_commands' => true]];
    }

    /**
     * Determine whether a raw listener belongs to the Sentry package.
     */
    private function isSentryListener(mixed $listener): bool
    {
        if (is_array($listener) && is_object($listener[0] ?? null)) {
            return str_starts_with($listener[0]::class, 'Hypervel\Sentry\\');
        }

        if (is_string($listener)) {
            return str_starts_with($listener, 'Hypervel\Sentry\\');
        }

        if ($listener instanceof Closure) {
            $function = new ReflectionFunction($listener);
            $owner = $function->getClosureThis() ?? $function->getClosureScopeClass()?->getName();
            $class = is_object($owner) ? $owner::class : (string) $owner;

            return str_starts_with($class, 'Hypervel\Sentry\\');
        }

        return false;
    }
}
