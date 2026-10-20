<?php

declare(strict_types=1);

namespace Hypervel\Pennant\Drivers;

use Closure;
use Hypervel\Pennant\Contracts\CanListStoredFeatures;
use Hypervel\Pennant\Contracts\CanSetManyFeaturesForScopes;
use Hypervel\Pennant\Contracts\Driver;
use Hypervel\Pennant\Contracts\HasFlushableCache;
use Hypervel\Pennant\Events\UnknownFeatureResolved;
use Hypervel\Pennant\Feature;
use Hypervel\Pennant\FeaturePartition;
use Hypervel\Pennant\PennantState;
use Hypervel\Support\Facades\Event;
use stdClass;

class ArrayDriver implements CanListStoredFeatures, CanSetManyFeaturesForScopes, Driver, HasFlushableCache
{
    /**
     * The store's name.
     */
    protected string $name;

    /**
     * The resolver of the store's current partition.
     *
     * @var null|(Closure(): FeaturePartition)
     */
    protected ?Closure $partitionResolver;

    /**
     * The feature state resolvers.
     *
     * @var array<string, (callable(mixed): mixed)>
     */
    protected array $featureStateResolvers;

    /**
     * The sentinel value for unknown features.
     */
    protected stdClass $unknownFeatureValue;

    /**
     * Create a new driver instance.
     *
     * Resolved feature states live in the current coroutine's PennantState, so
     * each request, job and child coroutine starts with its own values.
     *
     * @param null|(Closure(): FeaturePartition) $partitionResolver
     * @param array<string, (callable(mixed $scope): mixed)> $featureStateResolvers
     */
    public function __construct(string $name, ?Closure $partitionResolver, array $featureStateResolvers)
    {
        $this->name = $name;
        $this->partitionResolver = $partitionResolver;
        $this->featureStateResolvers = $featureStateResolvers;

        $this->unknownFeatureValue = new stdClass;
    }

    /**
     * Define an initial feature flag state resolver.
     *
     * Boot or tests only. The definition persists on the store for the worker
     * lifetime and applies to every subsequent request.
     *
     * @param (callable(mixed $scope): mixed) $resolver
     */
    public function define(string $feature, callable $resolver): void
    {
        $this->featureStateResolvers[$feature] = $resolver;
    }

    /**
     * Retrieve the names of all defined features.
     *
     * @return array<string>
     */
    public function defined(): array
    {
        return array_keys($this->featureStateResolvers);
    }

    /**
     * Retrieve the names of all stored features.
     *
     * @return array<string>
     */
    public function stored(): array
    {
        return array_keys(PennantState::current()->arrayValues[$this->name][$this->partitionKey()] ?? []);
    }

    /**
     * Get multiple feature flag values.
     *
     * @param array<string, array<int, mixed>> $features
     * @return array<string, array<int, mixed>>
     */
    public function getAll(array $features): array
    {
        $state = PennantState::current();
        $partition = $this->partitionKey();
        $results = [];

        foreach ($features as $feature => $scopes) {
            $results[$feature] = [];

            foreach ($scopes as $index => $scope) {
                $results[$feature][$index] = $this->retrieve($state, $partition, (string) $feature, $scope);
            }
        }

        return $results;
    }

    /**
     * Retrieve a feature flag's value.
     */
    public function get(string $feature, mixed $scope): mixed
    {
        return $this->retrieve(PennantState::current(), $this->partitionKey(), $feature, $scope);
    }

    /**
     * Retrieve a feature flag's value from the given state, resolving and storing it when missing.
     */
    protected function retrieve(PennantState $state, string $partition, string $feature, mixed $scope): mixed
    {
        $scopeKey = Feature::serializeScope($scope);

        if (array_key_exists($scopeKey, $state->arrayValues[$this->name][$partition][$feature] ?? [])) {
            return $state->arrayValues[$this->name][$partition][$feature][$scopeKey];
        }

        $value = $this->resolveValue($feature, $scope);

        if ($value === $this->unknownFeatureValue) {
            return false;
        }

        return $state->arrayValues[$this->name][$partition][$feature][$scopeKey] = $value;
    }

    /**
     * Determine the initial value for a given feature and scope.
     */
    protected function resolveValue(string $feature, mixed $scope): mixed
    {
        if ($this->missingResolver($feature)) {
            if (Event::hasListeners(UnknownFeatureResolved::class)) {
                Event::dispatch(new UnknownFeatureResolved($feature, $scope));
            }

            return $this->unknownFeatureValue;
        }

        return $this->featureStateResolvers[$feature]($scope);
    }

    /**
     * Set a feature flag's value.
     */
    public function set(string $feature, mixed $scope, mixed $value): void
    {
        PennantState::current()->arrayValues[$this->name][$this->partitionKey()][$feature][Feature::serializeScope($scope)] = $value;
    }

    /**
     * Set multiple feature flag values.
     *
     * @param list<array{ feature: string, scope: mixed, value: mixed }> $features
     */
    public function setAll(array $features): void
    {
        $state = PennantState::current();
        $partition = $this->partitionKey();

        foreach ($features as $featureData) {
            $state->arrayValues[$this->name][$partition][$featureData['feature']][Feature::serializeScope($featureData['scope'])] = $featureData['value'];
        }
    }

    /**
     * Set a feature flag's value for all scopes.
     */
    public function setForAllScopes(string $feature, mixed $value): void
    {
        $state = PennantState::current();
        $partition = $this->partitionKey();

        foreach ($state->arrayValues[$this->name][$partition][$feature] ?? [] as $scope => $currentValue) {
            $state->arrayValues[$this->name][$partition][$feature][$scope] = $value;
        }
    }

    /**
     * Delete a feature flag's value.
     */
    public function delete(string $feature, mixed $scope): void
    {
        unset(PennantState::current()->arrayValues[$this->name][$this->partitionKey()][$feature][Feature::serializeScope($scope)]);
    }

    /**
     * Purge the given feature from storage.
     */
    public function purge(?array $features): void
    {
        $state = PennantState::current();
        $partition = $this->partitionKey();

        if ($features === null) {
            unset($state->arrayValues[$this->name][$partition]);
        } else {
            foreach ($features as $feature) {
                unset($state->arrayValues[$this->name][$partition][$feature]);
            }
        }
    }

    /**
     * Determine if the feature does not have a resolver available.
     */
    protected function missingResolver(string $feature): bool
    {
        return ! array_key_exists($feature, $this->featureStateResolvers);
    }

    /**
     * Get the key of the store's current partition.
     */
    protected function partitionKey(): string
    {
        return $this->partitionResolver === null ? '' : ($this->partitionResolver)()->key();
    }

    /**
     * Flush the resolved feature states.
     */
    public function flushCache(): void
    {
        unset(PennantState::current()->arrayValues[$this->name]);
    }
}
