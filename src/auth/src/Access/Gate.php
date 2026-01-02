<?php

declare(strict_types=1);

namespace Hypervel\Auth\Access;

use Closure;
use Exception;
use Hyperf\Collection\Arr;
use Hyperf\Contract\ContainerInterface;
use Hyperf\Di\Exception\NotFoundException;
use Hyperf\Stringable\Str;
use Hypervel\Auth\Access\Events\GateEvaluated;
use Hypervel\Auth\Contracts\Authenticatable;
use Hypervel\Auth\Contracts\Gate as GateContract;
use InvalidArgumentException;
use Psr\EventDispatcher\EventDispatcherInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionParameter;

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
    protected ?Response $defaultDenialResponse;

    /**
     * Create a new gate instance.
     *
     * @param ContainerInterface $container the container instance
     * @param Closure $userResolver the user resolver callable
     * @param array $abilities all of the defined abilities
     * @param array $policies all of the defined policies
     * @param array $beforeCallbacks all of the registered before callbacks
     * @param array $afterCallbacks all of the registered after callbacks
     */
    public function __construct(
        protected ContainerInterface $container,
        protected Closure $userResolver,
        protected array $abilities = [],
        protected array $policies = [],
        protected array $beforeCallbacks = [],
        protected array $afterCallbacks = []
    ) {
    }

    /**
     * Determine if a given ability has been defined.
     */
    public function has(array|string $ability): bool
    {
        $abilities = is_array($ability) ? $ability : func_get_args();

        foreach ($abilities as $ability) {
            if (! isset($this->abilities[$ability])) {
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
    public function allowIf(bool|Closure|Response $condition, ?string $message = null, ?string $code = null): Response
    {
        return $this->authorizeOnDemand($condition, $message, $code, true);
    }

    /**
     * Perform an on-demand authorization check. Throw an authorization exception if the condition or callback is true.
     *
     * @throws AuthorizationException
     */
    public function denyIf(bool|Closure|Response $condition, ?string $message = null, ?string $code = null): Response
    {
        return $this->authorizeOnDemand($condition, $message, $code, false);
    }

    /**
     * Authorize a given condition or callback.
     *
     * @throws AuthorizationException
     */
    protected function authorizeOnDemand(bool|Closure|Response $condition, ?string $message, ?string $code, bool $allowWhenResponseIs): Response
    {
        $user = $this->resolveUser();

        if ($condition instanceof Closure) {
            $response = $this->canBeCalledWithUser($user, $condition)
                            ? $condition($user)
                            : new Response(false, $message, $code);
        } else {
            $response = $condition;
        }

        return with($response instanceof Response ? $response : new Response(
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
    public function define(string $ability, array|callable|string $callback): static
    {
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
        return function () use ($ability, $callback) {
            if (str_contains($callback, '@')) {
                [$class, $method] = Str::parseCallback($callback);
            } else {
                $class = $callback;
            }

            $policy = $this->resolvePolicy($class);

            $arguments = func_get_args();

            $user = array_shift($arguments);

            $result = $this->callPolicyBefore(
                $policy,
                $user,
                $ability,
                $arguments
            );

            if (! is_null($result)) {
                return $result;
            }

            return isset($method)
                    ? $policy->{$method}(...func_get_args())
                    : $policy(...func_get_args());
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
     * Determine if the given ability should be granted for the current user.
     */
    public function allows(string $ability, mixed $arguments = []): bool
    {
        return $this->check($ability, $arguments);
    }

    /**
     * Determine if the given ability should be denied for the current user.
     */
    public function denies(string $ability, mixed $arguments = []): bool
    {
        return ! $this->allows($ability, $arguments);
    }

    /**
     * Determine if all of the given abilities should be granted for the current user.
     */
    public function check(iterable|string $abilities, mixed $arguments = []): bool
    {
        return collect($abilities)->every(
            fn ($ability) => $this->inspect($ability, $arguments)->allowed()
        );
    }

    /**
     * Determine if any one of the given abilities should be granted for the current user.
     */
    public function any(iterable|string $abilities, mixed $arguments = []): bool
    {
        return collect($abilities)->contains(fn ($ability) => $this->check($ability, $arguments));
    }

    /**
     * Determine if all of the given abilities should be denied for the current user.
     */
    public function none(iterable|string $abilities, mixed $arguments = []): bool
    {
        return ! $this->any($abilities, $arguments);
    }

    /**
     * Determine if the given ability should be granted for the current user.
     *
     * @throws AuthorizationException
     */
    public function authorize(string $ability, mixed $arguments = []): Response
    {
        return $this->inspect($ability, $arguments)->authorize();
    }

    /**
     * Inspect the user for the given ability.
     */
    public function inspect(string $ability, mixed $arguments = []): Response
    {
        try {
            $result = $this->raw($ability, $arguments);

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
    protected function canBeCalledWithUser(?Authenticatable $user, mixed $class, ?string $method = null): bool
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
        try {
            $reflection = new ReflectionClass($class);

            $method = $reflection->getMethod($method);
        } catch (Exception) {
            return false;
        }

        $parameters = $method->getParameters();

        return isset($parameters[0]) && $this->parameterAllowsGuests($parameters[0]);
    }

    /**
     * Determine if the callback allows guests.
     *
     * @throws ReflectionException
     */
    protected function callbackAllowsGuests(callable $callback): bool
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
    protected function callAuthCallback(?Authenticatable $user, string $ability, array $arguments): bool|Response|null
    {
        $callback = $this->resolveAuthCallback($user, $ability, $arguments);

        return $callback($user, ...$arguments);
    }

    /**
     * Call all of the before callbacks and return if a result is given.
     *
     * @return null|bool|void
     */
    protected function callBeforeCallbacks(?Authenticatable $user, string $ability, array $arguments)
    {
        foreach ($this->beforeCallbacks as $before) {
            if (! $this->canBeCalledWithUser($user, $before)) {
                continue;
            }

            if (! is_null($result = $before($user, $ability, $arguments))) {
                return $result;
            }
        }
    }

    /**
     * Call all of the after callbacks with check result.
     */
    protected function callAfterCallbacks(?Authenticatable $user, string $ability, array $arguments, bool|Response|null $result): bool|Response|null
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
    protected function dispatchGateEvaluatedEvent(?Authenticatable $user, string $ability, array $arguments, bool|Response|null $result): void
    {
        if (! $this->container->has(EventDispatcherInterface::class)) {
            return;
        }

        $this->container->get(EventDispatcherInterface::class)->dispatch(
            new GateEvaluated($user, $ability, $result, $arguments)
        );
    }

    /**
     * Resolve the callable for the given ability and arguments.
     */
    protected function resolveAuthCallback(?Authenticatable $user, string $ability, array $arguments): callable
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
     *
     * @return mixed|void
     * @throws InvalidArgumentException
     */
    public function getPolicyFor(object|string $class)
    {
        if (is_object($class)) {
            $class = get_class($class);
        }

        if (isset($this->policies[$class])) {
            return $this->resolvePolicy($this->policies[$class]);
        }

        foreach ($this->policies as $expected => $policy) {
            if (is_subclass_of($class, $expected)) {
                return $this->resolvePolicy($policy);
            }
        }
    }

    /**
     * Build a policy class instance of the given type.
     *
     * @throws NotFoundException
     */
    public function resolvePolicy(string $class): mixed
    {
        return $this->container->get($class);
    }

    /**
     * Resolve the callback for a policy check.
     */
    protected function resolvePolicyCallback(?Authenticatable $user, string $ability, array $arguments, mixed $policy): bool|callable
    {
        if (! is_callable([$policy, $this->formatAbilityToMethod($ability)])) {
            return false;
        }

        return function () use ($user, $ability, $arguments, $policy) {
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

            $method = $this->formatAbilityToMethod($ability);

            return $this->callPolicyMethod($policy, $method, $user, $arguments);
        };
    }

    /**
     * Call the "before" method on the given policy, if applicable.
     *
     * @return mixed|void
     */
    protected function callPolicyBefore(mixed $policy, ?Authenticatable $user, string $ability, array $arguments)
    {
        if (! method_exists($policy, 'before')) {
            return;
        }

        if ($this->canBeCalledWithUser($user, $policy, 'before')) {
            return $policy->before($user, $ability, ...$arguments);
        }
    }

    /**
     * Call the appropriate method on the given policy.
     *
     * @return mixed|void
     */
    protected function callPolicyMethod(mixed $policy, string $method, ?Authenticatable $user, array $arguments)
    {
        // If this first argument is a string, that means they are passing a class name
        // to the policy. We will remove the first argument from this argument array
        // because this policy already knows what type of models it can authorize.
        if (isset($arguments[0]) && is_string($arguments[0])) {
            array_shift($arguments);
        }

        if (! is_callable([$policy, $method])) {
            return;
        }

        if ($this->canBeCalledWithUser($user, $policy, $method)) {
            return $policy->{$method}($user, ...$arguments);
        }
    }

    /**
     * Format the policy ability into a method name.
     */
    protected function formatAbilityToMethod(string $ability): string
    {
        return str_contains($ability, '-') ? Str::camel($ability) : $ability;
    }

    /**
     * Get a gate instance for the given user.
     */
    public function forUser(?Authenticatable $user): static
    {
        $callback = fn () => $user;

        return new static(
            $this->container,
            $callback,
            $this->abilities,
            $this->policies,
            $this->beforeCallbacks,
            $this->afterCallbacks
        );
    }

    /**
     * Resolve the user from the user resolver.
     */
    protected function resolveUser(): mixed
    {
        return call_user_func($this->userResolver);
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
}
