<?php

declare(strict_types=1);

namespace Hypervel\Auth\Access;

use Hypervel\Context\ApplicationContext;
use Hypervel\Contracts\Auth\Access\Gate;
use Hypervel\Contracts\Auth\Authenticatable;

use function Hypervel\Support\enum_value;

trait AuthorizesRequests
{
    /**
     * Authorize a given action for the current user.
     *
     * @throws AuthorizationException
     */
    public function authorize(mixed $ability, mixed $arguments = []): Response
    {
        [$ability, $arguments] = $this->parseAbilityAndArguments($ability, $arguments);

        return ApplicationContext::getContainer()->get(Gate::class)->authorize($ability, $arguments);
    }

    /**
     * Authorize a given action for a user.
     *
     * @throws AuthorizationException
     */
    public function authorizeForUser(?Authenticatable $user, mixed $ability, mixed $arguments = []): Response
    {
        [$ability, $arguments] = $this->parseAbilityAndArguments($ability, $arguments);

        return app(Gate::class)->forUser($user)->authorize($ability, $arguments);
    }

    /**
     * Guesses the ability's name if it wasn't provided.
     */
    protected function parseAbilityAndArguments(mixed $ability, mixed $arguments = []): array
    {
        $ability = enum_value($ability);

        if (is_string($ability) && ! str_contains($ability, '\\')) {
            return [$ability, $arguments];
        }

        $method = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)[2]['function'];

        return [$this->normalizeGuessedAbilityName($method), $ability];
    }

    /**
     * Normalize the ability name that has been guessed from the method name.
     */
    protected function normalizeGuessedAbilityName(string $ability): string
    {
        $map = $this->resourceAbilityMap();

        return $map[$ability] ?? $ability;
    }

    /**
     * Get the map of resource methods to ability names.
     */
    protected function resourceAbilityMap(): array
    {
        return [
            'index' => 'viewAny',
            'show' => 'view',
            'create' => 'create',
            'store' => 'create',
            'edit' => 'update',
            'update' => 'update',
            'destroy' => 'delete',
        ];
    }
}
