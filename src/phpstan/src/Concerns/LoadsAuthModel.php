<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Concerns;

use Hypervel\Auth\AuthManager;
use Hypervel\Auth\GenericUser;
use Hypervel\Contracts\Config\Repository as ConfigRepository;

use function array_keys;
use function in_array;
use function is_array;
use function is_string;

/**
 * Infers user types from the configured guard providers, assuming a guard returns its
 * provider's users.
 *
 * Upstream's AuthsMethodsExtension, which gives the auth user contracts their models'
 * methods, is omitted so contracts stay honest; user() return types carry the model.
 */
trait LoadsAuthModel
{
    /**
     * Get the user models configured for the given guards, or for every guard.
     *
     * Returns an empty list when any of those guards' users cannot be established,
     * so the call keeps its declared type. Upstream skips such guards, which
     * omits the users they return.
     *
     * @param ?list<string> $guardNames
     * @return list<class-string>
     */
    private function getAuthModels(AuthManager $manager, ConfigRepository $config, ?array $guardNames = null): array
    {
        $guards = $config->get('auth.guards');
        $providers = $config->get('auth.providers');

        if (! is_array($guards) || ! is_array($providers)) {
            return [];
        }

        /** @var array{array<string, mixed>, array<string, mixed>} $customCreators */
        $customCreators = (fn (): array => [$this->customCreators, $this->customProviderCreators])->call($manager);
        $authModels = [];

        foreach ($guardNames ?? array_keys($guards) as $guardName) {
            $authModel = $this->getGuardAuthModel($guards[$guardName] ?? null, $providers, ...$customCreators);

            if ($authModel === null) {
                return [];
            }

            if (! in_array($authModel, $authModels, strict: true)) {
                $authModels[] = $authModel;
            }
        }

        return $authModels;
    }

    /**
     * Get the user class the given guard configuration's provider returns.
     *
     * @param array<array-key, mixed> $providers
     * @param array<string, mixed> $guardCreators
     * @param array<string, mixed> $providerCreators
     * @return ?class-string
     */
    private function getGuardAuthModel(mixed $guard, array $providers, array $guardCreators, array $providerCreators): ?string
    {
        $provider = is_array($guard) ? ($guard['provider'] ?? null) : null;

        if (! is_string($provider) || $provider === '' || ! is_array($providers[$provider] ?? null)) {
            return null;
        }

        $driver = $providers[$provider]['driver'] ?? null;

        // A custom provider creator replaces the built-in provider of the same name.
        if (! is_string($driver) || isset($providerCreators[$driver])) {
            return null;
        }

        $guardDriver = $guard['driver'] ?? null;
        $model = $providers[$provider]['model'] ?? null;

        return match ($driver) {
            'eloquent' => is_string($model) && $model !== '' ? $model : null,
            // Other guards can return users from elsewhere: Sanctum accepts its session
            // guards' users when its provider, like the database one, has no model.
            'database' => in_array($guardDriver, ['session', 'token'], true) && ! isset($guardCreators[$guardDriver])
                ? GenericUser::class
                : null,
            default => null,
        };
    }
}
