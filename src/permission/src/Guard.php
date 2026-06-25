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
     * @var array<class-string, mixed>
     */
    protected static array $defaultGuardNames = [];

    /**
     * @var array<class-string<Model>, string>
     */
    protected static array $modelKeyNames = [];

    /**
     * @var array<string, null|class-string<Model>>
     */
    protected static array $providerModels = [];

    /**
     * @var array<class-string, array<int, string>>
     */
    protected static array $configAuthGuards = [];

    /**
     * @var array<string, null|class-string<Model>>
     */
    protected static array $modelsForGuards = [];

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
            $guardName = self::getDefaultGuardNameProperty($class);
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
        if (array_key_exists($provider, self::$providerModels)) {
            return self::$providerModels[$provider];
        }

        $providerConfig = self::config()->array("auth.providers.{$provider}", []);

        // Handle LDAP provider or standard Eloquent provider
        if (isset($providerConfig['driver']) && $providerConfig['driver'] === 'ldap') {
            /** @var null|class-string<Model> */
            return self::$providerModels[$provider] = $providerConfig['database']['model'] ?? null;
        }

        if (isset($providerConfig['model'])) {
            /** @var class-string<Model> */
            return self::$providerModels[$provider] = $providerConfig['model'];
        }

        return self::$providerModels[$provider] = null;
    }

    /**
     * Get the default guard_name property for a model class.
     */
    protected static function getDefaultGuardNameProperty(string $class): mixed
    {
        if (array_key_exists($class, self::$defaultGuardNames)) {
            return self::$defaultGuardNames[$class];
        }

        return self::$defaultGuardNames[$class] = (new ReflectionClass($class))->getDefaultProperties()['guard_name'] ?? null;
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
        if (isset(self::$configAuthGuards[$class])) {
            return new Collection(self::$configAuthGuards[$class]);
        }

        return new Collection(self::$configAuthGuards[$class] = (new Collection(self::guards()))
            ->map(function (array $guard): ?string {
                if (! isset($guard['provider'])) {
                    return null;
                }

                /** @var string $provider */
                $provider = $guard['provider'];

                return static::getProviderModel($provider);
            })
            ->filter(fn ($model) => $class === $model)
            ->keys()
            ->values()
            ->all());
    }

    /**
     * Get the model associated with a given guard name.
     */
    public static function getModelForGuard(string $guard): ?string
    {
        if (array_key_exists($guard, self::$modelsForGuards)) {
            return self::$modelsForGuards[$guard];
        }

        $provider = self::config()->get("auth.guards.{$guard}.provider");

        if (! $provider) {
            return self::$modelsForGuards[$guard] = null;
        }

        /** @var string $provider */
        return self::$modelsForGuards[$guard] = static::getProviderModel($provider);
    }

    /**
     * Get a model's key name without repeatedly constructing the model.
     *
     * @param class-string<Model> $modelClass
     */
    public static function getModelKeyName(string $modelClass): string
    {
        return self::$modelKeyNames[$modelClass] ??= (new $modelClass)->getKeyName();
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

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        self::$defaultGuardNames = [];
        self::$modelKeyNames = [];
        self::$providerModels = [];
        self::$configAuthGuards = [];
        self::$modelsForGuards = [];
    }
}
