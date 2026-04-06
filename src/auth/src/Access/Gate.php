<?php

declare(strict_types=1);

namespace Hypervel\Auth\Access;

use Closure;
use Exception;
use Hypervel\Auth\Access\Events\GateEvaluated;
use Hypervel\Contracts\Auth\Access\Gate as GateContract;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\Eloquent\Attributes\UsePolicy;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Query\Expression;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Str;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionFunction;
use ReflectionParameter;
use RuntimeException;
use UnitEnum;
use WeakMap;

use function Hypervel\Support\enum_value;

class Gate implements GateContract
{
    use HandlesAuthorization;

    /**
     * All of the defined abilities using class@method notation.
     */
    protected array $stringCallbacks = [];

    /**
     * The default denial response for gates and policies.
     */
    protected ?Response $defaultDenialResponse = null;

    /**
     * The callback to be used to guess policy names.
     *
     * @var null|callable
     */
    protected $guessPolicyNamesUsingCallback;

    /**
     * Cached model class to policy class mappings.
     *
     * Stores the resolved policy class string (or false for "no policy found")
     * per model class. Persists for the worker lifetime — model-to-policy
     * mappings don't change at runtime. The policy *instance* is not cached
     * here; resolvePolicy() goes through the container each time.
     *
     * Explicit policies ($this->policies) bypass this cache entirely.
     *
     * @var array<class-string, class-string|false>
     */
    protected static array $policyClassCache = [];

    /**
     * Cached guest-access results for class methods.
     *
     * Stores whether a method's first parameter allows null (nullable type
     * or null default value). Persists for the worker lifetime — method
     * signatures don't change at runtime.
     *
     * @var array<string, bool>
     */
    protected static array $guestMethodCache = [];

    /**
     * Cached guest-access results for closures.
     *
     * WeakMap ensures entries disappear with the closure, preventing stale
     * results when object IDs are recycled during a long worker lifetime.
     *
     * @var null|WeakMap<Closure, bool>
     */
    protected static ?WeakMap $guestClosureCache = null;

    /**
     * Cached ability name to method name mappings.
     *
     * Persists for the worker lifetime — ability names are fixed strings.
     * Bounded by the number of unique ability names in the application.
     *
     * @var array<string, string>
     */
    protected static array $abilityMethodCache = [];

    /**
     * Create a new gate instance.
     */
    public function __construct(
        protected Container $container,
        protected Closure $userResolver,
        protected array $abilities = [],
        protected array $policies = [],
        protected array $beforeCallbacks = [],
        protected array $afterCallbacks = [],
        ?callable $guessPolicyNamesUsingCallback = null,
    ) {
        $this->guessPolicyNamesUsingCallback = $guessPolicyNamesUsingCallback;
    }

    /**
     * Determine if a given ability has been defined.
     */
    public function has(array|UnitEnum|string $ability): bool
    {
        $abilities = is_array($ability) ? $ability : func_get_args();

        foreach ($abilities as $ability) {
            if (! isset($this->abilities[(string) enum_value($ability)])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Perform an on-demand authorization check. Throw an authorization exception if the condition or callback is false.
     *
     * @throws AuthorizationException
     */
    public function allowIf(mixed $condition, ?string $message = null, ?string $code = null): Response
    {
        return $this->authorizeOnDemand($condition, $message, $code, true);
    }

    /**
     * Perform an on-demand authorization check. Throw an authorization exception if the condition or callback is true.
     *
     * @throws AuthorizationException
     */
    public function denyIf(mixed $condition, ?string $message = null, ?string $code = null): Response
    {
        return $this->authorizeOnDemand($condition, $message, $code, false);
    }

    /**
     * Authorize a given condition or callback.
     *
     * @throws AuthorizationException
     */
    protected function authorizeOnDemand(mixed $condition, ?string $message, ?string $code, bool $allowWhenResponseIs): Response
    {
        $user = $this->resolveUser();

        if ($condition instanceof Closure) {
            $response = $this->canBeCalledWithUser($user, $condition)
                ? $condition($user)
                : new Response(false, $message, $code);
        } else {
            $response = $condition;
        }

        return ($response instanceof Response ? $response : new Response(
            (bool) $response === $allowWhenResponseIs,
            $message,
            $code
        ))->authorize();
    }

    /**
     * Define a new ability.
     *
     * @throws InvalidArgumentException
     */
    public function define(UnitEnum|string $ability, array|callable|string $callback): static
    {
        $ability = (string) enum_value($ability);

        if (is_array($callback) && isset($callback[0]) && is_string($callback[0])) {
            $callback = $callback[0] . '@' . $callback[1];
        }

        if (is_callable($callback)) {
            $this->abilities[$ability] = $callback;
        } elseif (is_string($callback)) {
            $this->stringCallbacks[$ability] = $callback;

            $this->abilities[$ability] = $this->buildAbilityCallback($ability, $callback);
        } else {
            throw new InvalidArgumentException("Callback must be a callable, callback array, or a 'Class@method' string.");
        }

        return $this;
    }

    /**
     * Define abilities for a resource.
     */
    public function resource(string $name, string $class, ?array $abilities = null): static
    {
        $abilities = $abilities ?: [
            'viewAny' => 'viewAny',
            'view' => 'view',
            'create' => 'create',
            'update' => 'update',
            'delete' => 'delete',
        ];

        foreach ($abilities as $ability => $method) {
            $this->define($name . '.' . $ability, $class . '@' . $method);
        }

        return $this;
    }

    /**
     * Create the ability callback for a callback string.
     */
    protected function buildAbilityCallback(string $ability, string $callback): Closure
    {
        if (str_contains($callback, '@')) {
            [$class, $method] = Str::parseCallback($callback);
        } else {
            $class = $callback;
            $method = null;
        }

        return function () use ($ability, $class, $method) {
            $arguments = func_get_args();
            $user = array_shift($arguments);

            $policy = $this->resolvePolicy($class);

            $result = $this->callPolicyBefore(
                $policy,
                $user,
                $ability,
                $arguments
            );

            if (! is_null($result)) {
                return $result;
            }

            return $method !== null
                ? $policy->{$method}($user, ...$arguments)
                : $policy($user, ...$arguments);
        };
    }

    /**
     * Define a policy class for a given class type.
     */
    public function policy(string $class, string $policy): static
    {
        $this->policies[$class] = $policy;

        return $this;
    }

    /**
     * Register a callback to run before all Gate checks.
     */
    public function before(callable $callback): static
    {
        $this->beforeCallbacks[] = $callback;

        return $this;
    }

    /**
     * Register a callback to run after all Gate checks.
     */
    public function after(callable $callback): static
    {
        $this->afterCallbacks[] = $callback;

        return $this;
    }

    /**
     * Determine if all of the given abilities should be granted for the current user.
     */
    public function allows(iterable|UnitEnum|string $ability, mixed $arguments = []): bool
    {
        return $this->check($ability, $arguments);
    }

    /**
     * Determine if any of the given abilities should be denied for the current user.
     */
    public function denies(iterable|UnitEnum|string $ability, mixed $arguments = []): bool
    {
        return ! $this->allows($ability, $arguments);
    }

    /**
     * Determine if all of the given abilities should be granted for the current user.
     */
    public function check(iterable|UnitEnum|string $abilities, mixed $arguments = []): bool
    {
        foreach (is_iterable($abilities) ? $abilities : [$abilities] as $ability) {
            if (! $this->inspect($ability, $arguments)->allowed()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine if any one of the given abilities should be granted for the current user.
     */
    public function any(iterable|UnitEnum|string $abilities, mixed $arguments = []): bool
    {
        foreach (is_iterable($abilities) ? $abilities : [$abilities] as $ability) {
            if ($this->check($ability, $arguments)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if all of the given abilities should be denied for the current user.
     */
    public function none(iterable|UnitEnum|string $abilities, mixed $arguments = []): bool
    {
        return ! $this->any($abilities, $arguments);
    }

    /**
     * Determine if the given ability should be granted for the current user.
     *
     * @throws AuthorizationException
     */
    public function authorize(UnitEnum|string $ability, mixed $arguments = []): Response
    {
        return $this->inspect($ability, $arguments)->authorize();
    }

    /**
     * Inspect the user for the given ability.
     */
    public function inspect(UnitEnum|string $ability, mixed $arguments = []): Response
    {
        try {
            $result = $this->raw((string) enum_value($ability), $arguments);

            if ($result instanceof Response) {
                return $result;
            }

            return $result
                ? Response::allow()
                : ($this->defaultDenialResponse ?? Response::deny());
        } catch (AuthorizationException $e) {
            return $e->toResponse();
        }
    }

    /**
     * Get the raw result from the authorization callback.
     *
     * @throws AuthorizationException
     */
    public function raw(string $ability, mixed $arguments = []): mixed
    {
        $arguments = Arr::wrap($arguments);

        $user = $this->resolveUser();

        // First we will call the "before" callbacks for the Gate. If any of these give
        // back a non-null response, we will immediately return that result in order
        // to let the developers override all checks for some authorization cases.
        $result = $this->callBeforeCallbacks(
            $user,
            $ability,
            $arguments
        );

        if (is_null($result)) {
            $result = $this->callAuthCallback($user, $ability, $arguments);
        }

        // After calling the authorization callback, we will call the "after" callbacks
        // that are registered with the Gate, which allows a developer to do logging
        // if that is required for this application. Then we'll return the result.
        return tap($this->callAfterCallbacks(
            $user,
            $ability,
            $arguments,
            $result
        ), function ($result) use ($user, $ability, $arguments) {
            $this->dispatchGateEvaluatedEvent($user, $ability, $arguments, $result);
        });
    }

    /**
     * Determine whether the callback/method can be called with the given user.
     */
    protected function canBeCalledWithUser(mixed $user, mixed $class, ?string $method = null): bool
    {
        if (! is_null($user)) {
            return true;
        }

        if (! is_null($method)) {
            return $this->methodAllowsGuests($class, $method);
        }

        if (is_array($class)) {
            $className = is_string($class[0]) ? $class[0] : get_class($class[0]);

            return $this->methodAllowsGuests($className, $class[1]);
        }

        return $this->callbackAllowsGuests($class);
    }

    /**
     * Determine if the given class method allows guests.
     */
    protected function methodAllowsGuests(mixed $class, string $method): bool
    {
        $className = is_string($class) ? $class : get_class($class);
        $key = $className . '::' . $method;

        return static::$guestMethodCache[$key] ??= $this->resolveMethodAllowsGuests($className, $method);
    }

    /**
     * Resolve whether a class method allows guest users via reflection.
     */
    private function resolveMethodAllowsGuests(string $className, string $method): bool
    {
        try {
            $reflection = new ReflectionClass($className);

            $method = $reflection->getMethod($method);
        } catch (Exception) {
            return false;
        }

        $parameters = $method->getParameters();

        return isset($parameters[0]) && $this->parameterAllowsGuests($parameters[0]);
    }

    /**
     * Determine if the callback allows guests.
     */
    protected function callbackAllowsGuests(callable $callback): bool
    {
        if ($callback instanceof Closure) {
            $cache = static::$guestClosureCache ??= new WeakMap;

            return $cache[$callback] ??= $this->resolveCallbackAllowsGuests($callback);
        }

        return $this->resolveCallbackAllowsGuests($callback);
    }

    /**
     * Resolve whether a callable allows guest users via reflection.
     */
    private function resolveCallbackAllowsGuests(callable $callback): bool
    {
        $parameters = (new ReflectionFunction($callback))->getParameters();

        return isset($parameters[0]) && $this->parameterAllowsGuests($parameters[0]);
    }

    /**
     * Determine if the given parameter allows guests.
     */
    protected function parameterAllowsGuests(ReflectionParameter $parameter): bool
    {
        return ($parameter->hasType() && $parameter->allowsNull())
               || ($parameter->isDefaultValueAvailable() && is_null($parameter->getDefaultValue()));
    }

    /**
     * Resolve and call the appropriate authorization callback.
     */
    protected function callAuthCallback(mixed $user, string $ability, array $arguments): bool|Response|null
    {
        $callback = $this->resolveAuthCallback($user, $ability, $arguments);

        return $callback($user, ...$arguments);
    }

    /**
     * Call all of the before callbacks and return if a result is given.
     */
    protected function callBeforeCallbacks(mixed $user, string $ability, array $arguments): mixed
    {
        foreach ($this->beforeCallbacks as $before) {
            if (! $this->canBeCalledWithUser($user, $before)) {
                continue;
            }

            if (! is_null($result = $before($user, $ability, $arguments))) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Call all of the after callbacks with check result.
     */
    protected function callAfterCallbacks(mixed $user, string $ability, array $arguments, bool|Response|null $result): bool|Response|null
    {
        foreach ($this->afterCallbacks as $after) {
            if (! $this->canBeCalledWithUser($user, $after)) {
                continue;
            }

            $afterResult = $after($user, $ability, $result, $arguments);

            $result ??= $afterResult;
        }

        return $result;
    }

    /**
     * Dispatch a gate evaluation event.
     */
    protected function dispatchGateEvaluatedEvent(mixed $user, string $ability, array $arguments, bool|Response|null $result): void
    {
        if ($this->container->bound(Dispatcher::class)) {
            $this->container->make(Dispatcher::class)->dispatch(
                new GateEvaluated($user, $ability, $result, $arguments)
            );
        }
    }

    /**
     * Resolve the callable for the given ability and arguments.
     */
    protected function resolveAuthCallback(mixed $user, string $ability, array $arguments): callable
    {
        if (isset($arguments[0])
            && ! is_null($policy = $this->getPolicyFor($arguments[0]))
            && $callback = $this->resolvePolicyCallback($user, $ability, $arguments, $policy)) {
            return $callback;
        }

        if (isset($this->stringCallbacks[$ability])) {
            [$class, $method] = Str::parseCallback($this->stringCallbacks[$ability]);

            if ($this->canBeCalledWithUser($user, $class, $method ?: '__invoke')) {
                return $this->abilities[$ability];
            }
        }

        if (isset($this->abilities[$ability])
            && $this->canBeCalledWithUser($user, $this->abilities[$ability])) {
            return $this->abilities[$ability];
        }

        return function () {};
    }

    /**
     * Get a policy instance for a given class.
     */
    public function getPolicyFor(object|string $class): mixed
    {
        if (is_object($class)) {
            $class = get_class($class);
        }

        // Explicitly registered policies bypass the cache — they're a fast
        // hash lookup, and the policies array can be modified at runtime.
        if (isset($this->policies[$class])) {
            return $this->resolvePolicy($this->policies[$class]);
        }

        if (! array_key_exists($class, static::$policyClassCache)) {
            static::$policyClassCache[$class] = $this->resolvePolicyClass($class);
        }

        $policyClass = static::$policyClassCache[$class];

        return $policyClass !== false
            ? $this->resolvePolicy($policyClass)
            : null;
    }

    /**
     * Resolve the policy class for the given model class.
     *
     * Checks the UsePolicy attribute, convention-based guessing, and
     * subclass fallback. Returns the policy class string or false if
     * no policy is found.
     *
     * @return class-string|false
     */
    private function resolvePolicyClass(string $class): string|false
    {
        $policy = $this->getPolicyFromAttribute($class);

        if (! is_null($policy)) {
            return $policy;
        }

        foreach ($this->guessPolicyName($class) as $guessedPolicy) {
            if (class_exists($guessedPolicy)) {
                return $guessedPolicy;
            }
        }

        foreach ($this->policies as $expected => $policy) {
            if (is_subclass_of($class, $expected)) {
                return $policy;
            }
        }

        return false;
    }

    /**
     * Get the policy class from the UsePolicy attribute.
     *
     * @param class-string $class
     * @return null|class-string
     */
    protected function getPolicyFromAttribute(string $class): ?string
    {
        if (! class_exists($class)) {
            return null;
        }

        $attributes = (new ReflectionClass($class))->getAttributes(UsePolicy::class);

        return $attributes !== []
            ? $attributes[0]->newInstance()->class
            : null;
    }

    /**
     * Guess the policy name for the given class.
     */
    protected function guessPolicyName(string $class): array
    {
        if ($this->guessPolicyNamesUsingCallback) {
            return Arr::wrap(($this->guessPolicyNamesUsingCallback)($class));
        }

        $classDirname = str_replace('/', '\\', dirname(str_replace('\\', '/', $class)));

        $classDirnameSegments = explode('\\', $classDirname);

        return Arr::wrap(Collection::times(count($classDirnameSegments), function ($index) use ($class, $classDirnameSegments) {
            $classDirname = implode('\\', array_slice($classDirnameSegments, 0, $index));

            return $classDirname . '\Policies\\' . class_basename($class) . 'Policy';
        })->when(str_contains($classDirname, '\Models\\'), function ($collection) use ($class, $classDirname) {
            return $collection->concat([str_replace('\Models\\', '\Policies\\', $classDirname) . '\\' . class_basename($class) . 'Policy'])
                ->concat([str_replace('\Models\\', '\Models\Policies\\', $classDirname) . '\\' . class_basename($class) . 'Policy']);
        })->reverse()->values()->first(function ($class) {
            return class_exists($class);
        }) ?: [$classDirname . '\Policies\\' . class_basename($class) . 'Policy']);
    }

    /**
     * Specify a callback to be used to guess policy names.
     */
    public function guessPolicyNamesUsing(callable $callback): static
    {
        $this->guessPolicyNamesUsingCallback = $callback;

        // A custom guess callback changes how unregistered policies are resolved,
        // so any cached results from the default guesser may be stale.
        static::$policyClassCache = [];

        return $this;
    }

    /**
     * Build a policy class instance of the given type.
     */
    public function resolvePolicy(object|string $class): mixed
    {
        return $this->container->make($class);
    }

    /**
     * Resolve the callback for a policy check.
     */
    protected function resolvePolicyCallback(mixed $user, string $ability, array $arguments, mixed $policy): bool|callable
    {
        $method = $this->formatAbilityToMethod($ability);

        if (! is_callable([$policy, $method])) {
            return false;
        }

        return function () use ($user, $ability, $arguments, $policy, $method) {
            // This callback will be responsible for calling the policy's before method and
            // running this policy method if necessary. This is used to when objects are
            // mapped to policy objects in the user's configurations or on this class.
            $result = $this->callPolicyBefore(
                $policy,
                $user,
                $ability,
                $arguments
            );

            // When we receive a non-null result from this before method, we will return it
            // as the "final" results. This will allow developers to override the checks
            // in this policy to return the result for all rules defined in the class.
            if (! is_null($result)) {
                return $result;
            }

            return $this->callPolicyMethod($policy, $method, $user, $arguments);
        };
    }

    /**
     * Call the "before" method on the given policy, if applicable.
     */
    protected function callPolicyBefore(mixed $policy, mixed $user, string $ability, array $arguments): mixed
    {
        if (! method_exists($policy, 'before')) {
            return null;
        }

        if ($this->canBeCalledWithUser($user, $policy, 'before')) {
            return $policy->before($user, $ability, ...$arguments);
        }

        return null;
    }

    /**
     * Call the appropriate method on the given policy.
     */
    protected function callPolicyMethod(mixed $policy, string $method, mixed $user, array $arguments): mixed
    {
        // If this first argument is a string, that means they are passing a class name
        // to the policy. We will remove the first argument from this argument array
        // because this policy already knows what type of models it can authorize.
        if (isset($arguments[0]) && is_string($arguments[0])) {
            array_shift($arguments);
        }

        if (! is_callable([$policy, $method])) {
            return null;
        }

        if ($this->canBeCalledWithUser($user, $policy, $method)) {
            return $policy->{$method}($user, ...$arguments);
        }

        return null;
    }

    /**
     * Format the policy ability into a method name.
     */
    protected function formatAbilityToMethod(string $ability): string
    {
        return static::$abilityMethodCache[$ability]
            ??= (str_contains($ability, '-') ? Str::camel($ability) : $ability);
    }

    /**
     * Get a gate instance for the given user.
     */
    public function forUser(mixed $user): static
    {
        return new static(
            $this->container,
            fn () => $user,
            $this->abilities,
            $this->policies,
            $this->beforeCallbacks,
            $this->afterCallbacks,
            $this->guessPolicyNamesUsingCallback,
        );
    }

    /**
     * Resolve the user from the user resolver.
     */
    protected function resolveUser(): mixed
    {
        return ($this->userResolver)();
    }

    /**
     * Get all of the defined abilities.
     */
    public function abilities(): array
    {
        return $this->abilities;
    }

    /**
     * Get all of the defined policies.
     */
    public function policies(): array
    {
        return $this->policies;
    }

    /**
     * Set the default denial response for gates and policies.
     */
    public function defaultDenialResponse(Response $response): static
    {
        $this->defaultDenialResponse = $response;

        return $this;
    }

    /**
     * Set the container instance used by the gate.
     */
    public function setContainer(Container $container): static
    {
        $this->container = $container;

        return $this;
    }

    /**
     * Apply the policy's scope method to filter a query to authorized rows.
     *
     * Runs before() callbacks first. If a before callback returns true (allow all),
     * the query is returned unmodified. If it returns false (deny all), a "no rows"
     * constraint is added. If null (no opinion), the policy's *Scope method is called.
     *
     * After callbacks are not run — they expect a boolean result, not a Builder.
     *
     * @throws RuntimeException
     */
    public function scope(string $ability, Builder $query): Builder
    {
        $user = $this->resolveUser();

        $beforeResult = $this->callBeforeCallbacks($user, $ability, [$query->getModel()]);

        if ($beforeResult === true) {
            return $query;
        }

        if ($beforeResult === false) {
            $query->whereRaw('0 = 1');

            return $query;
        }

        $policy = $this->getPolicyFor($query->getModel());
        $method = $this->formatAbilityToMethod($ability) . 'Scope';

        if (! $policy || ! method_exists($policy, $method)) {
            throw new RuntimeException(
                'Policy [' . ($policy ? get_class($policy) : 'null')
                . '] does not define a [' . $method . '] method.'
            );
        }

        $policyBeforeResult = $this->callPolicyBefore($policy, $user, $ability, [$query->getModel()]);

        if ($policyBeforeResult === true) {
            return $query;
        }

        if ($policyBeforeResult === false) {
            $query->whereRaw('0 = 1');

            return $query;
        }

        if (! $this->canBeCalledWithUser($user, $policy, $method)) {
            $query->whereRaw('0 = 1');

            return $query;
        }

        return $policy->{$method}($user, $query);
    }

    /**
     * Get a SQL expression from the policy for per-row authorization.
     *
     * Runs before() callbacks first. If a before callback returns true,
     * DB::raw('true') is returned. If false, DB::raw('false'). If null,
     * the policy's *Select method is called.
     *
     * After callbacks are not run — they expect a boolean result, not an Expression.
     *
     * Accepts a Builder for full query context, or a model class/instance
     * as shorthand (internally creates a fresh query).
     *
     * @param Builder|class-string<Model>|Model $query
     *
     * @throws RuntimeException
     */
    public function select(string $ability, Builder|Model|string $query): Expression
    {
        if (! $query instanceof Builder) {
            $query = is_object($query)
                ? $query->newQuery()
                : (new $query)->newQuery();
        }

        $user = $this->resolveUser();

        $beforeResult = $this->callBeforeCallbacks($user, $ability, [$query->getModel()]);

        if ($beforeResult === true) {
            return DB::raw('true');
        }

        if ($beforeResult === false) {
            return DB::raw('false');
        }

        $policy = $this->getPolicyFor($query->getModel());
        $method = $this->formatAbilityToMethod($ability) . 'Select';

        if (! $policy || ! method_exists($policy, $method)) {
            throw new RuntimeException(
                'Policy [' . ($policy ? get_class($policy) : 'null')
                . '] does not define a [' . $method . '] method.'
            );
        }

        $policyBeforeResult = $this->callPolicyBefore($policy, $user, $ability, [$query->getModel()]);

        if ($policyBeforeResult === true) {
            return DB::raw('true');
        }

        if ($policyBeforeResult === false) {
            return DB::raw('false');
        }

        if (! $this->canBeCalledWithUser($user, $policy, $method)) {
            return DB::raw('false');
        }

        return $policy->{$method}($user, $query);
    }

    /**
     * Flush all static caches.
     *
     * Called between tests by AfterEachTestSubscriber for test isolation.
     */
    public static function flushState(): void
    {
        static::$policyClassCache = [];
        static::$guestMethodCache = [];
        static::$guestClosureCache = new WeakMap;
        static::$abilityMethodCache = [];
    }
}
