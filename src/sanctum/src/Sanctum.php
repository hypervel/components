<?php

declare(strict_types=1);

namespace Hypervel\Sanctum;

use Hypervel\Container\Container;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Sanctum\Contracts\HasAbilities;
use Mockery;
use Mockery\MockInterface;
use UnitEnum;

use function Hypervel\Support\enum_value;

class Sanctum
{
    /** @var class-string<PersonalAccessToken> */
    protected const string DEFAULT_PERSONAL_ACCESS_TOKEN_MODEL = PersonalAccessToken::class;

    protected const string DEFAULT_CURRENT_REQUEST_HOST_PLACEHOLDER = '__SANCTUM_CURRENT_REQUEST_HOST__';

    /**
     * The personal access client model class name.
     *
     * @var class-string<PersonalAccessToken>
     */
    public static string $personalAccessTokenModel = self::DEFAULT_PERSONAL_ACCESS_TOKEN_MODEL;

    /**
     * A callback that can get the token from the request.
     *
     * @var null|callable
     */
    public static $accessTokenRetrievalCallback;

    /**
     * A callback that can add to the validation of the access token.
     *
     * @var null|callable
     */
    public static $accessTokenAuthenticationCallback;

    /**
     * A placeholder to instruct Sanctum to include the current request host in the list of stateful domains.
     */
    public static string $currentRequestHostPlaceholder = self::DEFAULT_CURRENT_REQUEST_HOST_PLACEHOLDER;

    /**
     * Get the current application URL from the "APP_URL" environment variable - with port.
     */
    public static function currentApplicationUrlWithPort(): string
    {
        $appUrl = config('app.url');

        return $appUrl ? ',' . parse_url($appUrl, PHP_URL_HOST) . (parse_url($appUrl, PHP_URL_PORT) ? ':' . parse_url($appUrl, PHP_URL_PORT) : '') : '';
    }

    /**
     * Get a fixed token instructing Sanctum to include the current request host in the list of stateful domains.
     */
    public static function currentRequestHost(): string
    {
        return ',' . static::$currentRequestHostPlaceholder;
    }

    /**
     * Determine if the authenticatable model supports API tokens.
     */
    public static function supportsTokens(?Authenticatable $tokenable): bool
    {
        return $tokenable !== null
            && isset(class_uses_recursive($tokenable)[HasApiTokens::class]);
    }

    /**
     * Set the current user for the application with the given abilities.
     *
     * Tests only. This installs a Mockery token double and replaces the current
     * coroutine's authenticated user and default guard with test state.
     *
     * @template TUser of Authenticatable
     *
     * @param TUser $user
     * @param array<string|UnitEnum> $abilities
     * @return TUser
     */
    public static function actingAs(Authenticatable $user, array $abilities = [], string $guard = 'sanctum'): Authenticatable
    {
        $abilities = array_map(enum_value(...), $abilities);

        /** @var HasAbilities&MockInterface $token */
        $token = Mockery::mock(static::personalAccessTokenModel())->shouldIgnoreMissing(false);

        if (in_array('*', $abilities, true)) {
            $token->shouldReceive('can')->andReturn(true);
        } else {
            $expectation = $token->shouldReceive('can');
            // @phpstan-ignore method.notFound (A named shouldReceive() returns an expectation, not HigherOrderMessage.)
            $expectation->andReturnUsing(function (UnitEnum|string $ability) use ($abilities): bool {
                return in_array(enum_value($ability), $abilities, true);
            });
        }

        // @phpstan-ignore method.notFound (The documented HasApiTokens trait provides this method.)
        $user->withAccessToken($token);

        // @phpstan-ignore property.notFound (Eloquent and compatible authenticatables expose this testing flag.)
        if (isset($user->wasRecentlyCreated) && $user->wasRecentlyCreated) {
            // @phpstan-ignore property.notFound (Eloquent and compatible authenticatables expose this testing flag.)
            $user->wasRecentlyCreated = false;
        }

        $authManager = Container::getInstance()->make('auth');
        $authManager->guard($guard)->setUser($user);
        $authManager->shouldUse($guard);

        return $user;
    }

    /**
     * Set the personal access token model name.
     *
     * Boot-only. The model class name persists in a static property for the
     * worker lifetime and is used for every token resolution across all
     * coroutines.
     *
     * @param class-string<PersonalAccessToken> $model
     */
    public static function usePersonalAccessTokenModel(string $model): void
    {
        static::$personalAccessTokenModel = $model;
    }

    /**
     * Specify a callback that should be used to fetch the access token from the request.
     *
     * Boot-only. The callback persists in a static property for the worker
     * lifetime and runs on every token retrieval across all coroutines.
     */
    public static function getAccessTokenFromRequestUsing(?callable $callback): void
    {
        static::$accessTokenRetrievalCallback = $callback;
    }

    /**
     * Specify a callback that should be used to authenticate access tokens.
     *
     * Boot-only. The callback persists in a static property for the worker
     * lifetime and runs on every token authentication across all coroutines.
     */
    public static function authenticateAccessTokensUsing(callable $callback): void
    {
        static::$accessTokenAuthenticationCallback = $callback;
    }

    /**
     * Get the token model class name.
     *
     * @return class-string<PersonalAccessToken>
     */
    public static function personalAccessTokenModel(): string
    {
        return static::$personalAccessTokenModel;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$personalAccessTokenModel = self::DEFAULT_PERSONAL_ACCESS_TOKEN_MODEL;
        static::$accessTokenRetrievalCallback = null;
        static::$accessTokenAuthenticationCallback = null;
        static::$currentRequestHostPlaceholder = self::DEFAULT_CURRENT_REQUEST_HOST_PLACEHOLDER;
    }
}
