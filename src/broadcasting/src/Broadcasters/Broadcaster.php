<?php

declare(strict_types=1);

namespace Hypervel\Broadcasting\Broadcasters;

use Closure;
use Exception;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hypervel\Auth\AuthManager;
use Hypervel\Contracts\Broadcasting\Broadcaster as BroadcasterContract;
use Hypervel\Contracts\Broadcasting\HasBroadcastChannel;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Router\UrlRoutable;
use Hypervel\HttpMessage\Exceptions\AccessDeniedHttpException;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Reflector;
use ReflectionClass;
use ReflectionFunction;
use ReflectionParameter;

abstract class Broadcaster implements BroadcasterContract
{
    /**
     * The container instance.
     */
    protected Container $container;

    /**
     * The callback to resolve the authenticated user information.
     */
    protected ?Closure $authenticatedUserCallback = null;

    /**
     * The registered channel authenticators.
     */
    protected static array $channels = [];

    /**
     * The registered channel options.
     */
    protected static array $channelOptions = [];

    /**
     * Resolve the authenticated user payload for the incoming connection request.
     *
     * See: https://pusher.com/docs/channels/library_auth_reference/auth-signatures/#user-authentication.
     */
    public function resolveAuthenticatedUser(RequestInterface $request): ?array
    {
        if ($this->authenticatedUserCallback) {
            return $this->authenticatedUserCallback->__invoke($request);
        }

        return null;
    }

    /**
     * Register the user retrieval callback used to authenticate connections.
     *
     * See: https://pusher.com/docs/channels/library_auth_reference/auth-signatures/#user-authentication.
     */
    public function resolveAuthenticatedUserUsing(Closure $callback): void
    {
        $this->authenticatedUserCallback = $callback;
    }

    /**
     * Register a channel authenticator.
     */
    public function channel(HasBroadcastChannel|string $channel, callable|string $callback, array $options = []): static
    {
        if ($channel instanceof HasBroadcastChannel) {
            $channel = $channel->broadcastChannelRoute();
        } elseif (class_exists($channel) && is_a($channel, HasBroadcastChannel::class, true)) {
            $channel = (new $channel())->broadcastChannelRoute();
        }

        static::$channels[$channel] = $callback;

        static::$channelOptions[$channel] = $options;

        return $this;
    }

    /**
     * Authenticate the incoming request for a given channel.
     *
     * @throws AccessDeniedHttpException
     */
    protected function verifyUserCanAccessChannel(RequestInterface $request, string $channel): mixed
    {
        foreach (static::$channels as $pattern => $callback) {
            if (! $this->channelNameMatchesPattern($channel, $pattern)) {
                continue;
            }

            $parameters = $this->extractAuthParameters($pattern, $channel, $callback);

            $handler = $this->normalizeChannelHandlerToCallable($callback);

            $result = $handler($this->retrieveUser($channel), ...$parameters);

            if ($result === false) {
                throw new AccessDeniedHttpException();
            }
            if ($result) {
                return $this->validAuthenticationResponse($request, $result);
            }
        }

        throw new AccessDeniedHttpException();
    }

    /**
     * Extract the parameters from the given pattern and channel.
     */
    protected function extractAuthParameters(string $pattern, string $channel, callable|string $callback): array
    {
        $callbackParameters = $this->extractParameters($callback);

        return collect($this->extractChannelKeys($pattern, $channel))
            ->reject(fn ($value, $key) => is_numeric($key))
            ->map(fn ($value, $key) => $this->resolveBinding($key, $value, $callbackParameters))
            ->values()
            ->all();
    }

    /**
     * Extracts the parameters out of what the user passed to handle the channel authentication.
     *
     * @return ReflectionParameter[]
     */
    protected function extractParameters(callable|string $callback): array
    {
        if (is_callable($callback)) {
            return (new ReflectionFunction($callback))->getParameters();
        }

        return $this->extractParametersFromClass($callback);
    }

    /**
     * Extracts the parameters out of a class channel's "join" method.
     *
     * @return ReflectionParameter[]
     *
     * @throws Exception
     */
    protected function extractParametersFromClass(string $callback): array
    {
        $reflection = new ReflectionClass($callback);

        if (! $reflection->hasMethod('join')) {
            throw new Exception('Class based channel must define a "join" method.');
        }

        return $reflection->getMethod('join')->getParameters();
    }

    /**
     * Extract the channel keys from the incoming channel name.
     */
    protected function extractChannelKeys(string $pattern, string $channel): array
    {
        preg_match('/^' . preg_replace('/\{(.*?)\}/', '(?<$1>[^\.]+)', $pattern) . '/', $channel, $keys);

        return $keys;
    }

    /**
     * Resolve the given parameter binding.
     */
    protected function resolveBinding(string $key, string $value, array $callbackParameters): mixed
    {
        return $this->resolveImplicitBindingIfPossible(
            $key,
            $value,
            $callbackParameters
        );
    }

    /**
     * Resolve an implicit parameter binding if applicable.
     *
     * @throws AccessDeniedHttpException
     */
    protected function resolveImplicitBindingIfPossible(string $key, string $value, array $callbackParameters): mixed
    {
        foreach ($callbackParameters as $parameter) {
            if (! $this->isImplicitlyBindable($key, $parameter)) {
                continue;
            }

            $className = Reflector::getParameterClassName($parameter);

            if (is_null($model = (new $className())->resolveRouteBinding($value))) {
                throw new AccessDeniedHttpException();
            }

            return $model;
        }

        return $value;
    }

    /**
     * Determine if a given key and parameter is implicitly bindable.
     */
    protected function isImplicitlyBindable(string $key, ReflectionParameter $parameter): bool
    {
        return $parameter->getName() === $key
            && Reflector::isParameterSubclassOf($parameter, UrlRoutable::class);
    }

    /**
     * Format the channel array into an array of strings.
     */
    protected function formatChannels(array $channels): array
    {
        return array_map(function ($channel) {
            return (string) $channel;
        }, $channels);
    }

    /**
     * Normalize the given callback into a callable.
     *
     * @param mixed $callback
     * @return callable
     */
    protected function normalizeChannelHandlerToCallable($callback)
    {
        return is_callable($callback) ? $callback : function (...$args) use ($callback) {
            return $this->container->make($callback)->join(...$args);
        };
    }

    /**
     * Retrieve the authenticated user using the configured guard (if any).
     */
    protected function retrieveUser(string $channel): mixed
    {
        $options = $this->retrieveChannelOptions($channel);
        $guards = $options['guards'] ?? null;

        $auth = $this->container->make(AuthManager::class);

        if (is_null($guards)) {
            return $auth->user();
        }

        foreach (Arr::wrap($guards) as $guard) {
            $user = $auth->guard($guard)->user();
            if ($user) {
                return $user;
            }
        }

        return null;
    }

    /**
     * Retrieve options for a certain channel.
     */
    protected function retrieveChannelOptions(string $channel): array
    {
        foreach (static::$channelOptions as $pattern => $options) {
            if (! $this->channelNameMatchesPattern($channel, $pattern)) {
                continue;
            }

            return $options;
        }

        return [];
    }

    /**
     * Check if the channel name from the request matches a pattern from registered channels.
     */
    protected function channelNameMatchesPattern(string $channel, string $pattern): bool
    {
        return (bool) preg_match('/^' . preg_replace('/\{(.*?)\}/', '([^\.]+)', $pattern) . '$/', $channel);
    }

    /**
     * Get all of the registered channels.
     */
    public function getChannels(): Collection
    {
        return Collection::make(static::$channels);
    }

    /**
     * Flush the registered channels.
     */
    public static function flushChannels(): void
    {
        static::$channels = [];
        static::$channelOptions = [];
    }
}
