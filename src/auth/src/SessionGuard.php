<?php

declare(strict_types=1);

namespace Hypervel\Auth;

use Closure;
use Hypervel\Auth\Events\Attempting;
use Hypervel\Auth\Events\Authenticated;
use Hypervel\Auth\Events\CurrentDeviceLogout;
use Hypervel\Auth\Events\Failed;
use Hypervel\Auth\Events\Login;
use Hypervel\Auth\Events\Logout;
use Hypervel\Auth\Events\OtherDeviceLogout;
use Hypervel\Auth\Events\Validated;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Hypervel\Contracts\Auth\StatefulGuard;
use Hypervel\Contracts\Auth\SupportsBasicAuth;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Cookie\QueueingFactory as CookieJar;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Session\Session;
use Hypervel\Support\Arr;
use Hypervel\Support\Facades\Hash;
use Hypervel\Support\Str;
use Hypervel\Support\Timebox;
use Hypervel\Support\Traits\Macroable;
use InvalidArgumentException;
use RuntimeException;
use SensitiveParameter;
use stdClass;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class SessionGuard implements StatefulGuard, SupportsBasicAuth
{
    use GuardHelpers;
    use Macroable;

    /**
     * Sentinel value indicating "user was resolved but not found".
     */
    private static object $nullUserSentinel;

    /**
     * The number of minutes that the "remember me" cookie should be valid for.
     */
    protected int $rememberDuration = 576000;

    /**
     * The Illuminate cookie creator service.
     */
    protected ?CookieJar $cookie = null;

    /**
     * The event dispatcher instance.
     */
    protected ?Dispatcher $events = null;

    /**
     * The timebox instance.
     */
    protected Timebox $timebox;

    /**
     * The precomputed session name for this guard.
     */
    private readonly string $hashedName;

    /**
     * The precomputed recaller cookie name for this guard.
     */
    private readonly string $hashedRecallerName;

    /**
     * Create a new authentication guard.
     *
     * @param string $name The name of the guard. Typically "web".
     * @param Session $session the session used by the guard
     * @param Container $app the container instance for lazy request resolution
     * @param bool $rehashOnLogin indicates if passwords should be rehashed on login if needed
     * @param int $timeboxDuration the number of microseconds that the timebox should wait for
     * @param ?string $hashKey the key used to hash recaller cookie values
     */
    public function __construct(
        public readonly string $name,
        UserProvider $provider,
        protected Session $session,
        protected Container $app,
        ?Timebox $timebox = null,
        protected bool $rehashOnLogin = true,
        protected int $timeboxDuration = 200000,
        protected ?string $hashKey = null,
    ) {
        $this->provider = $provider;
        $this->timebox = $timebox ?: new Timebox;

        $classHash = sha1(static::class);
        $this->hashedName = 'login_' . $this->name . '_' . $classHash;
        $this->hashedRecallerName = 'remember_' . $this->name . '_' . $classHash;
    }

    /**
     * Get the currently authenticated user.
     *
     * Uses coroutine Context to cache the resolved user per-request,
     * since this guard is a process-global singleton. A sentinel value
     * caches "no user found" so repeated calls don't trigger redundant
     * provider lookups.
     */
    public function user(): ?AuthenticatableContract
    {
        self::$nullUserSentinel ??= new stdClass;

        if ($this->getContextState('loggedOut', false)) {
            return null;
        }

        // Check unstarted context first — an explicit setUser() call before
        // the session started takes precedence over any cached session lookup.
        $unstartedKey = $this->getUnstartedContextKey();
        $unstartedCached = CoroutineContext::get($unstartedKey);

        if ($unstartedCached === self::$nullUserSentinel) {
            return null;
        }

        if ($unstartedCached !== null) {
            return $unstartedCached;
        }

        // Check started-session Context cache — avoids redundant DB lookups.
        $contextKey = $this->getContextKey();
        $cached = CoroutineContext::get($contextKey);

        if ($cached === self::$nullUserSentinel) {
            return null;
        }

        if ($cached !== null) {
            return $cached;
        }

        $user = null;

        $id = $this->session->get($this->getName());

        // First we will try to load the user using the identifier in the session if
        // one exists. Otherwise we will check for a "remember me" cookie in this
        // request, and if one exists, attempt to retrieve the user using that.
        if (! is_null($id) && $user = $this->provider->retrieveById($id)) {
            $this->fireAuthenticatedEvent($user);
        }

        // If the user is null, but we decrypt a "recaller" cookie we can attempt to
        // pull the user data on that cookie which serves as a remember cookie on
        // the application. Once we have a user we can return it to the caller.
        if (is_null($user) && ! is_null($recaller = $this->recaller())) {
            $user = $this->userFromRecaller($recaller);

            if ($user) {
                $this->updateSession($user->getAuthIdentifier());

                $this->fireLoginEvent($user, true);
            }
        }

        CoroutineContext::set($contextKey, $user ?? self::$nullUserSentinel);

        return $user;
    }

    /**
     * Pull a user from the repository by its "remember me" cookie token.
     */
    protected function userFromRecaller(Recaller $recaller): ?AuthenticatableContract
    {
        if (! $recaller->valid() || $this->getContextState('recallAttempted', false)) {
            return null;
        }

        // If the user is null, but we decrypt a "recaller" cookie we can attempt to
        // pull the user data on that cookie which serves as a remember cookie on
        // the application. Once we have a user we can return it to the caller.
        $this->setContextState('recallAttempted', true);

        $user = $this->provider->retrieveByToken(
            $recaller->id(),
            $recaller->token()
        );

        $this->setContextState('viaRemember', ! is_null($user));

        return $user;
    }

    /**
     * Get the decrypted recaller cookie for the request.
     */
    protected function recaller(): ?Recaller
    {
        if ($recaller = $this->getRequest()->cookies->get($this->getRecallerName())) {
            return new Recaller($recaller);
        }

        return null;
    }

    /**
     * Get the ID for the currently authenticated user.
     */
    public function id(): int|string|null
    {
        if ($this->getContextState('loggedOut', false)) {
            return null;
        }

        return $this->user()
            ? $this->user()->getAuthIdentifier()
            : $this->session->get($this->getName());
    }

    /**
     * Log a user into the application without sessions or cookies.
     */
    public function once(array $credentials = []): bool
    {
        $this->fireAttemptEvent($credentials);

        if ($this->validate($credentials)) {
            $this->rehashPasswordIfRequired($this->getLastAttempted(), $credentials);

            $this->setUser($this->getLastAttempted());

            return true;
        }

        $this->fireFailedEvent($this->getLastAttempted(), $credentials);

        return false;
    }

    /**
     * Log the given user ID into the application without sessions or cookies.
     */
    public function onceUsingId(mixed $id): AuthenticatableContract|false
    {
        if (! is_null($user = $this->provider->retrieveById($id))) {
            $this->setUser($user);

            return $user;
        }

        return false;
    }

    /**
     * Validate a user's credentials.
     */
    public function validate(array $credentials = []): bool
    {
        return $this->timebox->call(function ($timebox) use ($credentials) {
            $this->setContextState(
                'lastAttempted',
                $user = $this->provider->retrieveByCredentials($credentials)
            );

            $validated = $this->hasValidCredentials($user, $credentials);

            if ($validated) {
                $timebox->returnEarly();
            }

            return $validated;
        }, $this->timeboxDuration);
    }

    /**
     * Attempt to authenticate using HTTP Basic Auth.
     *
     * @throws UnauthorizedHttpException
     */
    public function basic(string $field = 'email', array $extraConditions = []): ?Response
    {
        if ($this->check()) {
            return null;
        }

        // If a username is set on the HTTP basic request, we will return out without
        // interrupting the request lifecycle. Otherwise, we'll need to generate a
        // request indicating that the given credentials were invalid for login.
        if ($this->attemptBasic($this->getRequest(), $field, $extraConditions)) {
            return null;
        }

        return $this->failedBasicResponse();
    }

    /**
     * Perform a stateless HTTP Basic login attempt.
     *
     * @throws UnauthorizedHttpException
     */
    public function onceBasic(string $field = 'email', array $extraConditions = []): ?Response
    {
        $credentials = $this->basicCredentials($this->getRequest(), $field);

        if (! $this->once(array_merge($credentials, $extraConditions))) {
            return $this->failedBasicResponse();
        }

        return null;
    }

    /**
     * Attempt to authenticate using basic authentication.
     */
    protected function attemptBasic(Request $request, string $field, array $extraConditions = []): bool
    {
        if (! $request->getUser()) {
            return false;
        }

        return $this->attempt(array_merge(
            $this->basicCredentials($request, $field),
            $extraConditions
        ));
    }

    /**
     * Get the credential array for an HTTP Basic request.
     */
    protected function basicCredentials(Request $request, string $field): array
    {
        return [$field => $request->getUser(), 'password' => $request->getPassword()];
    }

    /**
     * Get the response for basic authentication.
     *
     * @throws UnauthorizedHttpException
     */
    protected function failedBasicResponse(): never
    {
        throw new UnauthorizedHttpException('Basic', 'Invalid credentials.');
    }

    /**
     * Attempt to authenticate a user using the given credentials.
     */
    public function attempt(array $credentials = [], bool $remember = false): bool
    {
        return $this->timebox->call(function ($timebox) use ($credentials, $remember) {
            $this->fireAttemptEvent($credentials, $remember);

            $this->setContextState(
                'lastAttempted',
                $user = $this->provider->retrieveByCredentials($credentials)
            );

            // If an implementation of UserInterface was returned, we'll ask the provider
            // to validate the user against the given credentials, and if they are in
            // fact valid we'll log the users into the application and return true.
            if ($this->hasValidCredentials($user, $credentials)) {
                $this->rehashPasswordIfRequired($user, $credentials);

                $this->login($user, $remember);

                $timebox->returnEarly();

                return true;
            }

            // If the authentication attempt fails we will fire an event so that the user
            // may be notified of any suspicious attempts to access their account from
            // an unrecognized user. A developer may listen to this event as needed.
            $this->fireFailedEvent($user, $credentials);

            return false;
        }, $this->timeboxDuration);
    }

    /**
     * Attempt to authenticate a user with credentials and additional callbacks.
     */
    public function attemptWhen(array $credentials = [], array|callable|null $callbacks = null, bool $remember = false): bool
    {
        return $this->timebox->call(function ($timebox) use ($credentials, $callbacks, $remember) {
            $this->fireAttemptEvent($credentials, $remember);

            $this->setContextState(
                'lastAttempted',
                $user = $this->provider->retrieveByCredentials($credentials)
            );

            // This method does the exact same thing as attempt, but also executes callbacks after
            // the user is retrieved and validated. If one of the callbacks returns falsy we do
            // not login the user. Instead, we will fail the specific authentication attempt.
            if ($this->hasValidCredentials($user, $credentials) && $this->shouldLogin($callbacks, $user)) {
                $this->rehashPasswordIfRequired($user, $credentials);

                $this->login($user, $remember);

                $timebox->returnEarly();

                return true;
            }

            $this->fireFailedEvent($user, $credentials);

            return false;
        }, $this->timeboxDuration);
    }

    /**
     * Determine if the user matches the credentials.
     */
    protected function hasValidCredentials(?AuthenticatableContract $user, array $credentials): bool
    {
        $validated = ! is_null($user) && $this->provider->validateCredentials($user, $credentials);

        if ($validated) {
            $this->fireValidatedEvent($user);
        }

        return $validated;
    }

    /**
     * Determine if the user should login by executing the given callbacks.
     */
    protected function shouldLogin(array|callable|null $callbacks, AuthenticatableContract $user): bool
    {
        foreach (Arr::wrap($callbacks) as $callback) {
            if (! $callback($user, $this)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Rehash the user's password if enabled and required.
     */
    protected function rehashPasswordIfRequired(AuthenticatableContract $user, #[SensitiveParameter] array $credentials): void
    {
        if ($this->rehashOnLogin) {
            $this->provider->rehashPasswordIfRequired($user, $credentials);
        }
    }

    /**
     * Log the given user ID into the application.
     */
    public function loginUsingId(mixed $id, bool $remember = false): AuthenticatableContract|false
    {
        if (! is_null($user = $this->provider->retrieveById($id))) {
            $this->login($user, $remember);

            return $user;
        }

        return false;
    }

    /**
     * Log a user into the application.
     */
    public function login(AuthenticatableContract $user, bool $remember = false): void
    {
        $this->updateSession($user->getAuthIdentifier());

        // If the user should be permanently "remembered" by the application we will
        // queue a permanent cookie that contains the encrypted copy of the user
        // identifier. We will then decrypt this later to retrieve the users.
        if ($remember) {
            $this->ensureRememberTokenIsSet($user);

            $this->queueRecallerCookie($user);
        }

        // If we have an event dispatcher instance set we will fire an event so that
        // any listeners will hook into the authentication events and run actions
        // based on the login and logout events fired from the guard instances.
        $this->fireLoginEvent($user, $remember);

        $this->setUser($user);
    }

    /**
     * Update the session with the given ID and regenerate the session's token.
     */
    protected function updateSession(mixed $id): void
    {
        $this->session->put($this->getName(), $id);

        $this->session->regenerate(true);
    }

    /**
     * Create a new "remember me" token for the user if one doesn't already exist.
     */
    protected function ensureRememberTokenIsSet(AuthenticatableContract $user): void
    {
        if (empty($user->getRememberToken())) {
            $this->cycleRememberToken($user);
        }
    }

    /**
     * Queue the recaller cookie into the cookie jar.
     */
    protected function queueRecallerCookie(AuthenticatableContract $user): void
    {
        $this->getCookieJar()->queue($this->createRecaller(
            $user->getAuthIdentifier() . '|'
            . $user->getRememberToken() . '|'
            . $this->hashPasswordForCookie($user->getAuthPassword())
        ));
    }

    /**
     * Create a "remember me" cookie for a given ID.
     */
    protected function createRecaller(string $value): Cookie
    {
        return $this->getCookieJar()->make($this->getRecallerName(), $value, $this->getRememberDuration());
    }

    /**
     * Create a HMAC of the password hash for storage in cookies.
     */
    public function hashPasswordForCookie(string $passwordHash): string
    {
        return hash_hmac(
            'sha256',
            $passwordHash,
            $this->hashKey ?? 'base-key-for-password-hash-mac'
        );
    }

    /**
     * Log the user out of the application.
     */
    public function logout(): void
    {
        $user = $this->user();

        $this->clearUserDataFromStorage();

        if (! is_null($user) && ! empty($user->getRememberToken())) {
            $this->cycleRememberToken($user);
        }

        // If we have an event dispatcher instance, we can fire off the logout event
        // so any further processing can be done. This allows the developer to be
        // listening for anytime a user signs out of this application manually.
        $this->dispatchIfListening(Logout::class, fn () => new Logout($this->name, $user));

        // Once we have fired the logout event we will clear the users out of memory
        // so they are no longer available as the user is no longer considered as
        // being signed into this application and should not be available here.
        $this->forgetUser();

        $this->setContextState('loggedOut', true);
    }

    /**
     * Log the user out of the application on their current device only.
     *
     * This method does not cycle the "remember" token.
     */
    public function logoutCurrentDevice(): void
    {
        $user = $this->user();

        $this->clearUserDataFromStorage();

        // If we have an event dispatcher instance, we can fire off the logout event
        // so any further processing can be done. This allows the developer to be
        // listening for anytime a user signs out of this application manually.
        $this->dispatchIfListening(CurrentDeviceLogout::class, fn () => new CurrentDeviceLogout($this->name, $user));

        // Once we have fired the logout event we will clear the users out of memory
        // so they are no longer available as the user is no longer considered as
        // being signed into this application and should not be available here.
        $this->forgetUser();

        $this->setContextState('loggedOut', true);
    }

    /**
     * Remove the user data from the session and cookies.
     */
    protected function clearUserDataFromStorage(): void
    {
        $this->session->remove($this->getName());

        $this->getCookieJar()->unqueue($this->getRecallerName());

        if (! is_null($this->recaller())) {
            $this->getCookieJar()->queue(
                $this->getCookieJar()->forget($this->getRecallerName())
            );
        }
    }

    /**
     * Refresh the "remember me" token for the user.
     */
    protected function cycleRememberToken(AuthenticatableContract $user): void
    {
        $user->setRememberToken($token = Str::random(60));

        $this->provider->updateRememberToken($user, $token);
    }

    /**
     * Invalidate other sessions for the current user.
     *
     * The application must be using the AuthenticateSession middleware.
     *
     * @throws AuthenticationException
     */
    public function logoutOtherDevices(#[SensitiveParameter] string $password): ?AuthenticatableContract
    {
        if (! $this->user()) {
            return null;
        }

        $result = $this->rehashUserPasswordForDeviceLogout($password);

        if ($this->recaller()
            || $this->getCookieJar()->hasQueued($this->getRecallerName())) {
            $this->queueRecallerCookie($this->user());
        }

        $this->fireOtherDeviceLogoutEvent($this->user());

        return $result;
    }

    /**
     * Rehash the current user's password for logging out other devices via AuthenticateSession.
     *
     * @throws InvalidArgumentException
     */
    protected function rehashUserPasswordForDeviceLogout(#[SensitiveParameter] string $password): ?AuthenticatableContract
    {
        $user = $this->user();

        if (! Hash::check($password, $user->getAuthPassword())) {
            throw new InvalidArgumentException('The given password does not match the current password.');
        }

        $this->provider->rehashPasswordIfRequired(
            $user,
            ['password' => $password],
            force: true
        );

        return $user;
    }

    /**
     * Register an authentication attempt event listener.
     */
    public function attempting(callable $callback): void
    {
        $this->events?->listen(Attempting::class, $callback);
    }

    /**
     * Fire the attempt event with the arguments.
     */
    protected function fireAttemptEvent(array $credentials, bool $remember = false): void
    {
        $this->dispatchIfListening(Attempting::class, fn () => new Attempting($this->name, $credentials, $remember));
    }

    /**
     * Fire the validated event if the dispatcher is set.
     */
    protected function fireValidatedEvent(AuthenticatableContract $user): void
    {
        $this->dispatchIfListening(Validated::class, fn () => new Validated($this->name, $user));
    }

    /**
     * Fire the login event if the dispatcher is set.
     */
    protected function fireLoginEvent(AuthenticatableContract $user, bool $remember = false): void
    {
        $this->dispatchIfListening(Login::class, fn () => new Login($this->name, $user, $remember));
    }

    /**
     * Fire the authenticated event if the dispatcher is set.
     */
    protected function fireAuthenticatedEvent(AuthenticatableContract $user): void
    {
        $this->dispatchIfListening(Authenticated::class, fn () => new Authenticated($this->name, $user));
    }

    /**
     * Fire the other device logout event if the dispatcher is set.
     */
    protected function fireOtherDeviceLogoutEvent(AuthenticatableContract $user): void
    {
        $this->dispatchIfListening(OtherDeviceLogout::class, fn () => new OtherDeviceLogout($this->name, $user));
    }

    /**
     * Fire the failed authentication attempt event with the given arguments.
     */
    protected function fireFailedEvent(?AuthenticatableContract $user, array $credentials): void
    {
        $this->dispatchIfListening(Failed::class, fn () => new Failed($this->name, $user, $credentials));
    }

    /**
     * Dispatch the given event if listeners are registered.
     */
    protected function dispatchIfListening(string $eventClass, Closure $event): void
    {
        if ($this->events?->hasListeners($eventClass)) {
            $this->events->dispatch($event());
        }
    }

    /**
     * Get the last user we attempted to authenticate.
     */
    public function getLastAttempted(): ?AuthenticatableContract
    {
        return $this->getContextState('lastAttempted');
    }

    /**
     * Get a unique identifier for the auth session value.
     */
    public function getName(): string
    {
        return $this->hashedName;
    }

    /**
     * Get the name of the cookie used to store the "recaller".
     */
    public function getRecallerName(): string
    {
        return $this->hashedRecallerName;
    }

    /**
     * Determine if the user was authenticated via "remember me" cookie.
     */
    public function viaRemember(): bool
    {
        return $this->getContextState('viaRemember', false);
    }

    /**
     * Get the number of minutes the remember me cookie should be valid for.
     */
    protected function getRememberDuration(): int
    {
        return $this->rememberDuration;
    }

    /**
     * Set the number of minutes the remember me cookie should be valid for.
     */
    public function setRememberDuration(int $minutes): static
    {
        $this->rememberDuration = $minutes;

        return $this;
    }

    /**
     * Get the cookie creator instance used by the guard.
     *
     * @throws RuntimeException
     */
    public function getCookieJar(): CookieJar
    {
        if (! isset($this->cookie)) {
            throw new RuntimeException('Cookie jar has not been set.');
        }

        return $this->cookie;
    }

    /**
     * Set the cookie creator instance used by the guard.
     */
    public function setCookieJar(CookieJar $cookie): void
    {
        $this->cookie = $cookie;
    }

    /**
     * Get the event dispatcher instance.
     */
    public function getDispatcher(): ?Dispatcher
    {
        return $this->events;
    }

    /**
     * Set the event dispatcher instance.
     */
    public function setDispatcher(Dispatcher $events): void
    {
        $this->events = $events;
    }

    /**
     * Get the session store used by the guard.
     */
    public function getSession(): Session
    {
        return $this->session;
    }

    /**
     * Return the currently cached user.
     */
    public function getUser(): ?AuthenticatableContract
    {
        return $this->user();
    }

    /**
     * Determine if the guard has a user instance.
     */
    public function hasUser(): bool
    {
        self::$nullUserSentinel ??= new stdClass;

        $unstartedCached = CoroutineContext::get($this->getUnstartedContextKey());

        if ($unstartedCached !== null && $unstartedCached !== self::$nullUserSentinel) {
            return true;
        }

        $cached = CoroutineContext::get($this->getContextKey());

        return $cached !== null && $cached !== self::$nullUserSentinel;
    }

    /**
     * Set the current user.
     *
     * Uses coroutine Context for per-request isolation. Routes to the
     * "unstarted" key if the session hasn't started yet (e.g. during
     * middleware before session initialization).
     */
    public function setUser(AuthenticatableContract $user): static
    {
        if (! $this->session->isStarted()) {
            CoroutineContext::set($this->getUnstartedContextKey(), $user);
        } else {
            CoroutineContext::set($this->getContextKey(), $user);
        }

        $this->setContextState('loggedOut', false);

        $this->fireAuthenticatedEvent($user);

        return $this;
    }

    /**
     * Forget the current user.
     */
    public function forgetUser(): static
    {
        CoroutineContext::forget($this->getContextKey());
        CoroutineContext::forget($this->getUnstartedContextKey());

        return $this;
    }

    /**
     * Get the current request instance.
     *
     * Resolved lazily from the container because this guard is a
     * process-global singleton and must not store per-request state.
     */
    public function getRequest(): Request
    {
        return $this->app->make('request');
    }

    /**
     * Get the timebox instance used by the guard.
     */
    public function getTimebox(): Timebox
    {
        return $this->timebox;
    }

    /**
     * Get the Context key for caching the authenticated user.
     */
    protected function getContextKey(): string
    {
        return "__auth.guards.{$this->name}.user." . $this->session->getId();
    }

    /**
     * Get the Context key for user set before session starts.
     */
    protected function getUnstartedContextKey(): string
    {
        return "__auth.guards.{$this->name}.user.unstarted";
    }

    /**
     * Get a per-request state value from Context.
     */
    protected function getContextState(string $key, mixed $default = null): mixed
    {
        return CoroutineContext::get("__auth.guards.{$this->name}.{$key}", $default);
    }

    /**
     * Set a per-request state value in Context.
     */
    protected function setContextState(string $key, mixed $value): void
    {
        CoroutineContext::set("__auth.guards.{$this->name}.{$key}", $value);
    }
}
