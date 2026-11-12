<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Coroutine\RestrictedCalls;

use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Http\Client\Factory;
use Hypervel\Support\Attributes\BootOnly;
use Hypervel\Support\Attributes\BootOrTestsOnly;
use Hypervel\Support\Attributes\TestsOnly;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Facades\Facade;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\TestCase;

class Registry
{
    #[BootOnly]
    public static function register(string $name): void
    {
    }

    #[TestsOnly]
    public function fake(): void
    {
    }

    #[BootOrTestsOnly(whenShared: true)]
    public function configure(array $options): void
    {
    }

    #[BootOnly(whenNotDefault: 'map')]
    public function map(?array $map = null): ?array
    {
        return $map;
    }

    #[BootOnly(whenProvided: 'duration')]
    public function remember(?int $duration = null, bool $refresh = false): ?int
    {
        return $duration;
    }
}

class FreshRegistry extends Registry
{
}

class RegistryFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'restricted.registry';
    }
}

class UnknownRegistryFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'restricted.unknown';
    }
}

class ReportController
{
    public function __construct(
        private readonly Registry $registry,
        private Registry $mutable,
        private readonly FreshRegistry $fresh,
    ) {
    }

    public function index(Registry $registry, FreshRegistry $fresh): void
    {
        Registry::register('static');
        $registry->fake();
        RegistryFacade::fake();
        UnknownRegistryFacade::fake();
        $registry->fake(...);

        $registry->configure([]);
        $fresh->configure([]);
        $this->registry->configure([]);
        $this->mutable->configure([]);
        $this->fresh->configure([]);
        RegistryFacade::configure([]);
        app(Registry::class)->configure([]);
        app(FreshRegistry::class)->configure([]);
        (new Registry)->configure([]);
        $local = new Registry;
        $local->configure([]);

        $this->conditions(null, []);
    }

    public function update(Registry $registry): void
    {
        $registry = new Registry;
        $registry->configure([]);
    }

    public function framework(): void
    {
        (new Factory)->globalOptions([]);
        Http::globalOptions([]);
        Date::useClass(CarbonImmutable::class);
        Config::set('app.name', 'Hypervel');
        Config::macro('restricted', static fn (): string => '');
    }

    private function conditions(?array $map, array $arguments): void
    {
        $this->registry->map();
        $this->registry->map(null);
        $this->registry->map(['a' => 'b']);
        $this->registry->map(map: ['a' => 'b']);
        $this->registry->map($map);
        $this->registry->map(...$arguments);
        $this->registry->remember();
        $this->registry->remember(60);
        $this->registry->remember(duration: null);
        $this->registry->remember(refresh: true);
        $this->registry->remember(...$arguments);
    }
}

class ProcessReport implements ShouldQueue
{
    use Dispatchable;

    public function __construct(private readonly Registry $registry)
    {
    }

    public function handle(Registry $registry): void
    {
        $registry->configure([]);
        $this->registry->configure([]);
    }
}

class ReceiveListener
{
    public function handle(): void
    {
        Registry::register('operation');
    }
}

class RegistryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Registry::register('boot');
        Http::globalOptions([]);
        $this->app->make(ProcessReport::class);

        Route::get('/closure', function (Registry $registry): void {
            Registry::register('closure');
            $registry->configure([]);
        });
    }
}

class RegistryTest extends TestCase
{
    public function testFake(): void
    {
        RegistryFacade::fake();
    }
}

class FactoryRegistry extends Registry
{
}

class ContextualRegistry extends Registry
{
}

class ReceiverController
{
    public function __construct(
        private readonly FactoryRegistry $shared,
        private readonly Registry $fresh,
        private readonly ContextualRegistry $unknown,
        private readonly ConfigRepository $config,
    ) {
    }

    public function index(): void
    {
        $this->shared->configure([]);
        $this->fresh->configure([]);
        $this->unknown->configure([]);
        app()->make(Registry::class)->configure([]);
        (new Container)->make(Registry::class)->configure([]);

        $this->config->set('app.name', 'Hypervel');
        $this->config['app.locale'] = 'en';
    }

    public function replace(Registry $registry): void
    {
        self::swap($registry);
        $registry->configure([]);
    }

    public function show(Registry $registry): void
    {
        $registry->configure([]);
    }

    private static function swap(Registry &$registry): void
    {
        $registry = new Registry;
    }
}

class ContextualConfigController
{
    public function __construct(private readonly Repository $config)
    {
    }

    public function update(): void
    {
        $this->config->set('app.name', 'Hypervel');
    }
}

class QueuedRegistryListener implements ShouldQueue
{
    public function __construct(private readonly Registry $registry)
    {
    }

    public function record(): void
    {
        $this->registry->configure([]);
    }
}

abstract class RegistryJob implements ShouldQueue
{
    use Dispatchable;

    public function handle(Registry $registry): void
    {
        $registry->configure([]);
    }
}

class FreshRegistryJob extends RegistryJob
{
}

class SharedRegistryJob extends RegistryJob
{
}

abstract class RegistryTask implements ShouldQueue
{
    use Dispatchable;

    public function handle(Registry $registry): void
    {
        $registry->configure([]);
    }
}

class SharedRegistryTask extends RegistryTask
{
}

class UnknownRegistryTask extends RegistryTask
{
}

abstract class RegistryListener implements ShouldQueue
{
    public function __construct(private readonly Registry $registry)
    {
    }

    public function record(): void
    {
        $this->registry->configure([]);
        $this->refresh();
    }

    public function handle(): void
    {
        $this->registry->configure([]);
    }

    protected function refresh(): void
    {
        $this->registry->configure([]);
    }
}

class InheritedRegistryListener extends RegistryListener
{
}

class SerializedRegistryJob extends RegistryListener
{
    use Dispatchable;
}
