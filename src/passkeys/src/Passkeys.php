<?php

declare(strict_types=1);

namespace Hypervel\Passkeys;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Contracts\Auth\StatefulGuard;
use Hypervel\Contracts\Config\Repository as Config;
use Hypervel\Http\Request;
use Hypervel\Passkeys\Contracts\PasskeyUser;
use RuntimeException;

class Passkeys
{
    private const DEFAULT_PASSKEY_MODEL = Passkey::class;

    private const DEFAULT_REGISTERS_ROUTES = true;

    /** @var class-string<Passkey> */
    private static string $passkeyModel = self::DEFAULT_PASSKEY_MODEL;

    private static bool $registersRoutes = self::DEFAULT_REGISTERS_ROUTES;

    /** @var null|Closure(Request, PasskeyUser, Passkey): bool */
    private static ?Closure $authorizeLoginUsing = null;

    /** @var null|Closure(Request): string */
    private static ?Closure $relyingPartyIdResolver = null;

    /** @var null|Closure(Request): list<string> */
    private static ?Closure $allowedOriginsResolver = null;

    /** @var null|Closure(Request): (null|string) */
    private static ?Closure $redirectUsingCallback = null;

    /**
     * Get the relying party ID.
     */
    public static function relyingPartyId(): string
    {
        $callback = self::$relyingPartyIdResolver;
        $request = $callback instanceof Closure ? RequestContext::getOrNull() : null;

        $relyingPartyId = $request instanceof Request
            ? $callback($request)
            : self::config()->string('passkeys.relying_party_id');

        if (! is_string($relyingPartyId) || $relyingPartyId === '') {
            if ($request instanceof Request) {
                throw new RuntimeException("Passkey relying party ID resolver returned no value for host [{$request->getHost()}].");
            }

            throw new RuntimeException('Passkey relying party ID must not be empty.');
        }

        return $relyingPartyId;
    }

    // Intentionally omitted: web-auth/webauthn-lib 5.3 deprecates a non-empty RP name.

    /**
     * Register a callback to resolve the WebAuthn relying party ID for the current request.
     *
     * Boot-only. The callback persists in static state for the worker lifetime and affects every subsequent WebAuthn ceremony.
     *
     * @param null|(callable(Request): string) $callback
     */
    public static function resolveRelyingPartyIdUsing(?callable $callback): void
    {
        self::$relyingPartyIdResolver = $callback === null
            ? null
            : Closure::fromCallable($callback);
    }

    /**
     * Get the origins allowed to complete WebAuthn ceremonies.
     *
     * @return list<string>
     */
    public static function allowedOrigins(): array
    {
        $callback = self::$allowedOriginsResolver;
        $request = $callback instanceof Closure ? RequestContext::getOrNull() : null;

        $origins = $request instanceof Request
            ? $callback($request)
            : self::config()->array('passkeys.allowed_origins');

        $origins = is_array($origins) ? array_values(array_filter(
            $origins,
            static fn (mixed $origin): bool => is_string($origin) && $origin !== '',
        )) : [];

        if ($origins === []) {
            if ($request instanceof Request) {
                throw new RuntimeException("Passkey allowed origins resolver returned no values for host [{$request->getHost()}].");
            }

            throw new RuntimeException('At least one passkey allowed origin must be configured.');
        }

        return $origins;
    }

    /**
     * Register a callback to resolve WebAuthn allowed origins for the current request.
     *
     * Boot-only. The callback persists in static state for the worker lifetime and affects every subsequent WebAuthn ceremony.
     *
     * @param null|(callable(Request): list<string>) $callback
     */
    public static function resolveAllowedOriginsUsing(?callable $callback): void
    {
        self::$allowedOriginsResolver = $callback === null
            ? null
            : Closure::fromCallable($callback);
    }

    /**
     * Determine if allowed origins are currently resolved from request-aware state.
     */
    public static function hasRequestAwareAllowedOrigins(): bool
    {
        return self::$allowedOriginsResolver instanceof Closure
            && RequestContext::has();
    }

    /**
     * Get the WebAuthn timeout in milliseconds.
     *
     * @return positive-int
     */
    public static function timeout(): int
    {
        $timeout = self::config()->integer('passkeys.timeout');

        if ($timeout < 1) {
            throw new RuntimeException('Passkey timeout must be a positive integer.');
        }

        return $timeout;
    }

    /**
     * Get the passkey model class name.
     *
     * @return class-string<Passkey>
     */
    public static function passkeyModel(): string
    {
        return self::$passkeyModel;
    }

    /**
     * Set the passkey model class name.
     *
     * Boot-only. The model class persists in static state for the worker lifetime and affects every subsequent passkey query.
     *
     * @param class-string<Passkey> $model
     */
    public static function usePasskeyModel(string $model): void
    {
        self::$passkeyModel = $model;
    }

    // Intentionally omitted: Hypervel uses polymorphic passkey owners instead of a global user model.

    /**
     * Register a callback to authorize passkey logins before login.
     *
     * Boot-only. The callback persists in static state for the worker lifetime and affects every subsequent passkey login.
     *
     * @param null|(callable(Request, PasskeyUser, Passkey): bool) $callback
     */
    public static function authorizeLoginUsing(?callable $callback): void
    {
        self::$authorizeLoginUsing = $callback !== null
            ? Closure::fromCallable($callback)
            : null;
    }

    /**
     * Determine if a passkey-verified user should be allowed to log in.
     */
    public static function allowsLogin(Request $request, Passkey $passkey): bool
    {
        $user = $passkey->user;

        if (! $user instanceof PasskeyUser) {
            return false;
        }

        if (! self::$authorizeLoginUsing instanceof Closure) {
            return true;
        }

        return (bool) (self::$authorizeLoginUsing)($request, $user, $passkey);
    }

    /**
     * Register a callback to resolve the successful login redirect path.
     *
     * Boot-only. The callback persists in static state for the worker lifetime and affects every subsequent successful passkey login response.
     *
     * @param null|(callable(Request): (null|string)) $callback
     */
    public static function redirectUsing(?callable $callback): void
    {
        self::$redirectUsingCallback = $callback === null
            ? null
            : Closure::fromCallable($callback);
    }

    /**
     * Resolve the successful login redirect path for this request.
     */
    public static function redirectTo(Request $request): string
    {
        if (self::$redirectUsingCallback instanceof Closure) {
            $redirect = (self::$redirectUsingCallback)($request);

            if (is_string($redirect) && $redirect !== '') {
                return $redirect;
            }
        }

        return self::config()->string('passkeys.redirect');
    }

    /**
     * Get the current authentication guard name.
     */
    public static function guardName(): string
    {
        return self::container()
            ->make(AuthFactory::class)
            ->getDefaultDriver();
    }

    /**
     * Get the current stateful authentication guard.
     */
    public static function guard(): StatefulGuard
    {
        $guard = self::container()
            ->make(AuthFactory::class)
            ->guard(null);

        if (! $guard instanceof StatefulGuard) {
            throw new RuntimeException('Passkeys requires a stateful authentication guard.');
        }

        return $guard;
    }

    /**
     * Determine if Passkeys routes should be registered.
     */
    public static function shouldRegisterRoutes(): bool
    {
        return self::$registersRoutes;
    }

    /**
     * Configure Passkeys to not register its routes.
     *
     * Boot-only. The route registration flag persists in static state for the worker lifetime and affects route bootstrapping.
     */
    public static function ignoreRoutes(): void
    {
        self::$registersRoutes = false;
    }

    /**
     * Get the path to the package's migrations.
     */
    public static function migrationPath(): string
    {
        return __DIR__ . '/../database/migrations';
    }

    /**
     * Get the configured user handle secret.
     */
    public static function userHandleSecret(): string
    {
        $secret = self::config()->string('passkeys.user_handle_secret');

        if ($secret === '') {
            throw new RuntimeException('Passkey user handle secret must not be empty.');
        }

        return $secret;
    }

    /**
     * Get the config repository.
     */
    private static function config(): Config
    {
        return self::container()->make(Config::class);
    }

    /**
     * Get the container instance.
     */
    private static function container(): Container
    {
        return Container::getInstance();
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        self::$passkeyModel = self::DEFAULT_PASSKEY_MODEL;
        self::$registersRoutes = self::DEFAULT_REGISTERS_ROUTES;
        self::$authorizeLoginUsing = null;
        self::$relyingPartyIdResolver = null;
        self::$allowedOriginsResolver = null;
        self::$redirectUsingCallback = null;
    }
}
