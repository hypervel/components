<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerState;

use App\User;
use Closure;
use Hypervel\Container\Attributes\Bind;
use Hypervel\Container\Attributes\Cache;
use Hypervel\Container\Attributes\CurrentUser;
use Hypervel\Container\Attributes\Scoped;
use Hypervel\Container\Attributes\Singleton;
use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Container\SelfBuilding;
use Hypervel\Contracts\Container\Transient;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Contracts\Routing\UrlRoutable;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Foundation\Http\FormRequest;
use Hypervel\Http\Request;
use Hypervel\Support\Attributes\BootOnly;
use Hypervel\Support\Attributes\TestsOnly;
use Hypervel\Support\Facades\App;
use Hypervel\Support\Facades\Auth;
use WeakMap;

// Roots: constant resolutions without parameters.
function resolveServices(Container $container): void
{
    app(SharedService::class);
    resolve(ScopedService::class);
    $container->make(TransientService::class);
    App::make(SelfBuildingService::class);
    $container->get(ExecutionScopedService::class);
    $container->factory(BoundFreshService::class);
    app(FactoryBoundService::class);
    app(ServiceContract::class);
    app(BindAttributedService::class);
    app(RepositoryService::class);
    app(AssignedRepositoryService::class);
    app(AuthenticatableService::class);
    app(CurrentUserCacheService::class);
    app(RequestCaptureService::class);
    app(ConsumerService::class);
    app(OtherConsumer::class);
    app(CachedService::class);
    app(EarlyCaptureService::class);
    app(MemoCachedService::class);
    app(ParameterOnlyService::class, ['value' => 1]);
}

class SharedService
{
    public static array $memo = [];

    private static ?Request $current = null;

    private ?Request $request = null;

    /** @var array<int|string, int> */
    private array $visits = [];

    /** @var array<int, int> */
    private array $pages = [];

    /** @var array<string, bool> */
    private array $recent = [];

    private WeakMap $users;

    private int $count = 0;

    public function __construct(private NestedService $nested)
    {
        $this->users = new WeakMap;
    }

    public function remember(Request $request): void
    {
        $this->request = $request;
        static::$current = $request;
        $this->visits[$request->ip()] = 1;
        $this->visits[request()->path()] = 1;
        $this->visits[Auth::id()] = 1;
        $this->visits[$request->isMethod('post') ? 'post' : 'other'] = 1;
        $this->pages[$request->integer('page') % 1000] = 1;
        $this->users[$request->user()] = 1;
        $this->count++;
        static::$memo[$request->ip()] ??= 1;
    }

    public function rememberLatest(Request $request): void
    {
        $this->recent = [];
        $this->recent[$request->ip()] = true;
    }

    /**
     * Set the request used for testing.
     *
     * The request persists on the shared service.
     */
    #[TestsOnly]
    public function setRequestForTesting(Request $request): void
    {
        $this->request = $request;
    }

    #[TestsOnly(whenNotDefault: 'request')]
    public function request(?Request $request = null): ?Request
    {
        if ($request !== null) {
            $this->request = $request;
        }

        return $this->request;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$memo = [];
    }
}

class NestedService
{
    private ?User $user = null;

    public function remember(User $user): void
    {
        $this->user = $user;
    }
}

#[Scoped]
class ScopedService
{
    private ?Request $request = null;

    public function remember(Request $request): void
    {
        $this->request = $request;
    }
}

class TransientService implements Transient
{
    private ?Request $request = null;

    public function remember(Request $request): void
    {
        $this->request = $request;
    }
}

class SelfBuildingService implements SelfBuilding
{
    private ?Request $request = null;

    public static function newInstance(): static
    {
        return new static;
    }

    public function remember(Request $request): void
    {
        $this->request = $request;
    }
}

#[Singleton]
class SelfBuildingSingleton implements SelfBuilding
{
    public function __construct(public ?Request $request = null)
    {
    }

    public static function newInstance(): static
    {
        return new static;
    }
}

class ExecutionScopedService
{
    private ?Request $request = null;

    public function __construct(#[CurrentUser] public User $user)
    {
    }

    public function remember(Request $request): void
    {
        $this->request = $request;
    }
}

#[Singleton]
class CurrentUserSingleton
{
    public function __construct(#[CurrentUser] public User $user)
    {
    }
}

#[Singleton]
class TestingRequestHolder
{
    #[TestsOnly]
    public function __construct(public Request $request)
    {
    }
}

class CachedService
{
    private ?Request $request = null;

    public function __construct(#[Cache] public CacheRepository $cache)
    {
    }

    public function remember(Request $request): void
    {
        $this->request = $request;
    }
}

class MemoCachedService
{
    private ?Request $request = null;

    public function __construct(#[Cache(memo: true)] public CacheRepository $cache)
    {
    }

    public function remember(Request $request): void
    {
        $this->request = $request;
    }
}

class BoundFreshService
{
    private ?Request $request = null;

    public function remember(Request $request): void
    {
        $this->request = $request;
    }
}

class ScopedBoundService
{
    private ?Request $request = null;

    public function remember(Request $request): void
    {
        $this->request = $request;
    }
}

class SingletonBoundService
{
    private ?Request $request = null;

    public function remember(Request $request): void
    {
        $this->request = $request;
    }
}

class FactoryBoundService
{
    private ?Request $request = null;

    public function remember(Request $request): void
    {
        $this->request = $request;
    }
}

interface ServiceContract
{
}

class ContractImplementation implements ServiceContract
{
    private ?Request $request = null;

    public function remember(Request $request): void
    {
        $this->request = $request;
    }
}

#[Bind(ContractImplementation::class)]
class BindAttributedService
{
    private ?Request $request = null;

    public function remember(Request $request): void
    {
        $this->request = $request;
    }
}

#[Singleton]
class AttributeSingleton
{
    private ?Request $request = null;

    public function remember(Request $request): void
    {
        $this->request = $request;
    }
}

class ParameterOnlyService
{
    private ?Request $request = null;

    public function __construct(public int $value)
    {
    }

    public function remember(Request $request): void
    {
        $this->request = $request;
    }
}

// Constructor captures: the container passes a fresh model, but not the current user.
class RepositoryService
{
    public function __construct(protected User $model)
    {
    }
}

class AssignedRepositoryService
{
    protected User $model;

    public function __construct(User $model)
    {
        $this->model = $model;
    }
}

#[Singleton]
class ReassignedUserService
{
    private User $user;

    public function __construct(User $user)
    {
        $user = User::query()->firstOrFail();
        $this->user = $user;
    }
}

class EarlyCaptureService
{
    private User $model;

    public function __construct(User $model)
    {
        $this->model = $model;
        $model = User::query()->firstOrFail();
    }
}

class AuthenticatableService
{
    public function __construct(public Authenticatable $user)
    {
    }
}

class CurrentUserCacheService
{
    private ?Authenticatable $user;

    public function __construct()
    {
        $this->user = auth()->user();
    }
}

class RequestCaptureService
{
    public function __construct(private Request $request)
    {
    }
}

interface ContextualDependency
{
}

class ContextualImplementation implements ContextualDependency
{
    private ?Request $request = null;

    public function remember(Request $request): void
    {
        $this->request = $request;
    }
}

class ConsumerService
{
    public function __construct(public ContextualDependency $dependency, public User $model)
    {
    }
}

class OtherConsumer
{
    public function __construct(public User $model)
    {
    }
}

// HTTP roots and contexts.
class CountingMiddleware
{
    public static bool $seen = false;

    /** @var array<string, bool> */
    public static array $clients = [];

    private int $handled = 0;

    public function handle(Request $request, Closure $next): mixed
    {
        $this->handled++;
        static::$seen = true;
        static::$clients[$request->ip()] = true;
        config(['app.locale' => 'fr']);
        collect([1])->each(function (): void {
            static::$seen = true;
        });

        return $next($request);
    }
}

class ReportController
{
    private int $shown = 0;

    public function show(ReportService $report, RoutableService $routable, BoundRoutable $boundRoutable, ConfigRepository $config, ?DefaultedService $defaulted = null): string
    {
        $this->shown++;
        $config->set('app.timezone', 'UTC');
        $config['app.name'] = 'Hypervel';
        $config['app.name'] .= ' Reports';
        unset($config['app.url']);
        config()['app.locale'] = 'fr';
        $local = new ConfigRepository;
        $local->set('app.timezone', 'UTC');

        return '';
    }
}

abstract class BaseController
{
    protected int $hits = 0;

    public function index(): string
    {
        $this->hits++;

        return '';
    }
}

#[Scoped]
class ScopedController extends BaseController
{
}

#[Singleton]
class SharedNonController extends BaseController
{
}

class ReportService
{
    private ?Request $request = null;

    public function remember(Request $request): void
    {
        $this->request = $request;
    }
}

class RoutableService implements UrlRoutable
{
    private ?Request $request = null;

    public function remember(Request $request): void
    {
        $this->request = $request;
    }

    public function getRouteKey(): mixed
    {
        return 1;
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function resolveRouteBinding(mixed $value, ?string $field = null): ?Model
    {
        return null;
    }

    public function resolveChildRouteBinding(string $childType, mixed $value, ?string $field): ?Model
    {
        return null;
    }
}

class BoundRoutable extends RoutableService
{
}

class DefaultedService
{
    private ?Request $request = null;

    public function remember(Request $request): void
    {
        $this->request = $request;
    }
}

class BinderController
{
    public function show(BinderService $service): string
    {
        return '';
    }
}

class BinderService
{
    private ?Request $request = null;

    public function remember(Request $request): void
    {
        $this->request = $request;
    }
}

class ClosureService
{
    private ?Request $request = null;

    public function remember(Request $request): void
    {
        $this->request = $request;
    }
}

class StoreReportRequest extends FormRequest
{
    public static int $validated = 0;

    public function rules(): array
    {
        static::$validated++;

        return [];
    }
}

// Jobs, listeners and commands.
class ProcessReport implements ShouldQueue
{
    use Dispatchable;

    public static int $runs = 0;

    public function handle(JobService $service, Repository $config): void
    {
        static::$runs++;
        $config->set('app.debug', true);
    }
}

class LocalConfig extends ConfigRepository implements Transient
{
}

class ConfigureReport implements ShouldQueue
{
    use Dispatchable;

    public function handle(Repository $config): void
    {
        $config->set('app.timezone', 'UTC');
        $config = new LocalConfig;
        $config->set('app.debug', true);
        app(LocalConfig::class)->set('app.debug', true);
        app('config')->set('app.debug', true);
        app('settings')->set('app.debug', true);
    }
}

class ContextualConfigJob implements ShouldQueue
{
    use Dispatchable;

    public function handle(Repository $config): void
    {
        $config->set('app.debug', true);
    }
}

class JobService
{
    private ?Request $request = null;

    public function remember(Request $request): void
    {
        $this->request = $request;
    }
}

class ReportEvent
{
    public function __construct(public Request $request)
    {
    }
}

class SendReportListener implements ShouldQueue
{
    private ?Request $request = null;

    public function handle(ReportEvent $event): void
    {
        $this->request = $event->request;
    }
}

class UnregisteredEvent
{
    public function __construct(public Request $request)
    {
    }
}

class UnregisteredListener implements ShouldQueue
{
    public function handle(UnregisteredEvent $event): void
    {
    }
}

class MappedCommand implements ShouldQueue
{
    use Dispatchable;

    public function handle(MappedService $service): void
    {
    }
}

class MappedService
{
    private ?Request $request = null;

    public function remember(Request $request): void
    {
        $this->request = $request;
    }
}

class MappedHandler
{
    private ?MappedCommand $command = null;

    private ?Request $request = null;

    public function handle(MappedCommand $command): void
    {
        $this->command = $command;
    }

    public function remember(Request $request): void
    {
        $this->request = $request;
    }
}

// Helpers a request or job method calls on its own instance handle the same operation.
class HelperController
{
    private int $tracked = 0;

    private bool $primed = false;

    private bool $configured = false;

    private int $deferred = 0;

    public function __construct()
    {
        $this->prime();
    }

    public function index(): string
    {
        $this->track();
        $later = $this->defer(...);
        collect([1])->each(fn (): mixed => $this->defer());

        return '';
    }

    /**
     * The provider configures the controller once.
     */
    #[BootOnly]
    public function configure(): void
    {
        $this->apply();
    }

    private function track(): void
    {
        $this->tracked++;
        config(['app.tracked' => true]);
    }

    private function prime(): void
    {
        $this->primed = true;
    }

    private function apply(): void
    {
        $this->configured = true;
    }

    private function defer(): void
    {
        $this->deferred++;
    }
}

#[Scoped]
class ScopedHelperController
{
    private int $tracked = 0;

    public function index(): string
    {
        $this->track();

        return '';
    }

    private function track(): void
    {
        $this->tracked++;
    }
}

// The container binds the routed class to a subclass, whose override the inherited action calls.
abstract class BaseRecordingController
{
    protected int $recorded = 0;

    public function index(): string
    {
        $this->record();

        return '';
    }

    protected function record(): void
    {
        $this->recorded++;
    }
}

class OverridingRecordingController extends BaseRecordingController
{
    protected int $overridden = 0;

    protected function record(): void
    {
        $this->overridden++;
    }
}

// A private method runs in the class whose code calls it, whatever the receiver declares.
class LexicalParentController
{
    protected int $resolved = 0;

    public function index(): string
    {
        $this->resolve();

        return '';
    }

    private function resolve(): void
    {
        $this->resolved++;
    }
}

class LexicalChildController extends LexicalParentController
{
    protected int $childResolved = 0;

    public function resolve(): void
    {
        $this->childResolved++;
    }
}

class RebuildReport implements ShouldQueue
{
    use Dispatchable;

    public static int $rebuilt = 0;

    public function handle(): void
    {
        $this->count();
    }

    private function count(): void
    {
        static::$rebuilt++;
        config(['app.rebuilt' => true]);
    }
}

// config() reads its key by name, so named arguments may come in any order.
class NamedConfigJob implements ShouldQueue
{
    use Dispatchable;

    public function handle(): void
    {
        config(default: null, key: ['app.named' => true]);
        config(default: [], key: 'app.name');
        app(parameters: [], abstract: 'config')->set('app.named', true);
        app('config', ['option' => true])->set('app.named', true);
    }
}
