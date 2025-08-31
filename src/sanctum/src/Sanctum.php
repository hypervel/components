<?php

declare(strict_types=1);

namespace Hypervel\Sanctum;

use Hypervel\Auth\AuthManager;
use Hypervel\Context\ApplicationContext;
use Mockery;
use Mockery\MockInterface;

/**
 * @template TToken of \Hypervel\Sanctum\Contracts\HasAbilities = \Hypervel\Sanctum\PersonalAccessToken
 */
class Sanctum
{
    /**
     * The personal access client model class name.
     *
     * @var class-string<TToken>
     */
    public static string $personalAccessTokenModel = PersonalAccessToken::class;

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
     * Get the current application URL from the "APP_URL" environment variable - with port.
     */
    public static function currentApplicationUrlWithPort(): string
    {
        $appUrl = config('app.url');

        return $appUrl ? ',' . parse_url($appUrl, PHP_URL_HOST) . (parse_url($appUrl, PHP_URL_PORT) ? ':' . parse_url($appUrl, PHP_URL_PORT) : '') : '';
    }

    /**
     * Set the current user for the application with the given abilities.
     *
     * @param \Hypervel\Auth\Contracts\Authenticatable&\Hypervel\Sanctum\Contracts\HasApiTokens $user
     * @param array<string> $abilities
     */
    public static function actingAs($user, array $abilities = [], string $guard = 'sanctum'): mixed
    {
        /** @var \Hypervel\Sanctum\Contracts\HasAbilities&MockInterface $token */
        $token = Mockery::mock(self::personalAccessTokenModel())->shouldIgnoreMissing(false);

        if (in_array('*', $abilities)) {
            $token->shouldReceive('can')->andReturn(true);
        } else {
            /* @phpstan-ignore-next-line */
            $token->shouldReceive('can')->andReturnUsing(function (string $ability) use ($abilities) {
                return in_array($ability, $abilities);
            });
        }

        $user->withAccessToken($token);

        if (isset($user->wasRecentlyCreated) && $user->wasRecentlyCreated) {
            $user->wasRecentlyCreated = false;
        }

        // Set the user on the guard
        $authManager = ApplicationContext::getContainer()->get(AuthManager::class);
        $authManager->guard($guard)->setUser($user);

        return $user;
    }

    /**
     * Set the personal access token model name.
     *
     * @param class-string<TToken> $model
     */
    public static function usePersonalAccessTokenModel(string $model): void
    {
        static::$personalAccessTokenModel = $model;
    }

    /**
     * Specify a callback that should be used to fetch the access token from the request.
     */
    public static function getAccessTokenFromRequestUsing(?callable $callback): void
    {
        static::$accessTokenRetrievalCallback = $callback;
    }

    /**
     * Specify a callback that should be used to authenticate access tokens.
     */
    public static function authenticateAccessTokensUsing(callable $callback): void
    {
        static::$accessTokenAuthenticationCallback = $callback;
    }

    /**
     * Get the token model class name.
     *
     * @return class-string<TToken>
     */
    public static function personalAccessTokenModel(): string
    {
        return static::$personalAccessTokenModel;
    }
}
