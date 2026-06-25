<?php

declare(strict_types=1);

namespace Hypervel\Permission;

use Hypervel\Container\Container;
use Hypervel\Contracts\Auth\Access\Authorizable;
use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Collection;
use ReflectionClass;

class Guard
{
    /**
     * Return a collection of guard names suitable for the model,
     * as indicated by the presence of a $guard_name property or a guardName() method on the model.
     */
    public static function getNames(string|Model $model): Collection
    {
        $class = is_object($model) ? $model::class : $model;

        if (is_object($model)) {
            if (method_exists($model, 'guardName')) {
                $guardName = $model->guardName();
            } else {
                $guardName = $model->getAttributeValue('guard_name');
            }
        }

        if (! isset($guardName)) {
            $guardName = (new ReflectionClass($class))->getDefaultProperties()['guard_name'] ?? null;
        }

        if ($guardName) {
            return new Collection($guardName);
        }

        return self::getConfigAuthGuards($class);
    }

    /**
     * Get the model class associated with a given provider.
     *
     * @return null|class-string<Model>
     */
    protected static function getProviderModel(string $provider): ?string
    {
        $providerConfig = self::config()->array("auth.providers.{$provider}", []);

        // Handle LDAP provider or standard Eloquent provider
        if (isset($providerConfig['driver']) && $providerConfig['driver'] === 'ldap') {
            return $providerConfig['database']['model'] ?? null;
        }

        if (isset($providerConfig['model'])) {
            return $providerConfig['model'];
        }

        return null;
    }

    /**
     * Get the config repository.
     */
    protected static function config(): Repository
    {
        return Container::getInstance()->make('config');
    }

    /**
     * Get the auth factory.
     */
    protected static function auth(): AuthFactory
    {
        return Container::getInstance()->make(AuthFactory::class);
    }

    /**
     * Get the configured auth guards.
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function guards(): array
    {
        return self::config()->array('auth.guards', []);
    }

    /**
     * Get list of relevant guards for the $class model based on config(auth) settings.
     *
     * Lookup flow:
     * - get names of models for guards defined in auth.guards where a provider is set
     * - filter for provider models matching the model $class being checked
     * - keys() gives just the names of the matched guards
     * - return collection of guard names
     */
    protected static function getConfigAuthGuards(string $class): Collection
    {
        return (new Collection(self::guards()))
            ->map(function (array $guard): ?string {
                if (! isset($guard['provider'])) {
                    return null;
                }

                /** @var string $provider */
                $provider = $guard['provider'];

                return static::getProviderModel($provider);
            })
            ->filter(fn ($model) => $class === $model)
            ->keys();
    }

    /**
     * Get the model associated with a given guard name.
     */
    public static function getModelForGuard(string $guard): ?string
    {
        $provider = self::config()->get("auth.guards.{$guard}.provider");

        if (! $provider) {
            return null;
        }

        /** @var string $provider */
        return static::getProviderModel($provider);
    }

    /**
     * Lookup a guard name relevant for the $class model and the current user.
     */
    public static function getDefaultName(string|Model $class): string
    {
        $default = self::auth()->getDefaultDriver();

        $possibleGuards = static::getNames($class);

        if ($possibleGuards->contains($default)) {
            return $default;
        }

        return $possibleGuards->first() ?: $default;
    }

    /**
     * Lookup a Passport guard.
     */
    public static function getPassportClient(?string $guard): ?Authorizable
    {
        $guards = (new Collection(self::guards()))->where('driver', 'passport');

        if (! $guards->count()) {
            return null;
        }

        /** @var string $passportGuard */
        $passportGuard = $guards->keys()[0];

        $authGuard = self::auth()->guard($passportGuard);

        if (! method_exists($authGuard, 'client')) {
            return null;
        }

        $client = $authGuard->client();

        if (! $guard || ! $client) {
            return $client;
        }

        if (self::getNames($client)->contains($guard)) {
            return $client;
        }

        return null;
    }
}
