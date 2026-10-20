<?php

declare(strict_types=1);

namespace Hypervel\Pennant;

use Closure;
use Hypervel\Pennant\Drivers\Decorator;
use Hypervel\Support\Arr;
use Hypervel\Support\Enumerable;
use RuntimeException;
use UnitEnum;

class PendingScopedFeatureInteraction
{
    /**
     * The feature driver.
     */
    protected Decorator $driver;

    /**
     * The feature interaction scope.
     *
     * @var array<mixed>
     */
    protected array $scope = [];

    /**
     * Create a new Pending Scoped Feature Interaction instance.
     */
    public function __construct(Decorator $driver)
    {
        $this->driver = $driver;
    }

    /**
     * Add scope to the feature interaction.
     */
    public function for(mixed $scope): static
    {
        $this->scope = array_merge($this->scope, $scope instanceof Enumerable ? $scope->all() : Arr::wrap($scope));

        return $this;
    }

    /**
     * Load the feature into memory.
     *
     * @param array<int, string|UnitEnum>|string|UnitEnum $features
     * @return array<string, array<int, mixed>>
     */
    public function load(UnitEnum|string|array $features): array
    {
        $scope = $this->scope();

        return $this->driver->getAll(array_map(
            fn ($feature) => ['feature' => $feature, 'scope' => $scope],
            $this->normalize($features),
        ));
    }

    /**
     * Load the missing features into memory.
     *
     * @param array<int, string|UnitEnum>|string|UnitEnum $features
     * @return array<string, array<int, mixed>>
     */
    public function loadMissing(UnitEnum|string|array $features): array
    {
        $scope = $this->scope();

        return $this->driver->getAllMissing(array_map(
            fn ($feature) => ['feature' => $feature, 'scope' => $scope],
            $this->normalize($features),
        ));
    }

    /**
     * Load all defined features into memory.
     *
     * Each scope loads only the features whose resolvers accept it.
     *
     * @return array<string, array<int, mixed>>
     */
    public function loadAll(): array
    {
        $features = [];

        foreach ($this->scope() as $index => $scope) {
            foreach ($this->driver->definedFeaturesForScope($scope) as $feature) {
                $features[$feature]['feature'] = $feature;
                $features[$feature]['scope'][$index] = $scope;
            }
        }

        return $this->driver->getAll(array_values($features));
    }

    /**
     * Get the value of the flag.
     */
    public function value(UnitEnum|string $feature): mixed
    {
        return head($this->values([$feature]));
    }

    /**
     * Get the values of the flag.
     *
     * @param array<int, string|UnitEnum> $features
     * @return array<string, mixed>
     */
    public function values(array $features): array
    {
        if (count($this->scope()) > 1) {
            throw new RuntimeException('It is not possible to retrieve the values for multiple scopes.');
        }

        $features = $this->normalize($features);
        $scope = $this->scope()[0];

        $this->loadMissingBeforeReading($features);

        $values = [];

        foreach ($features as $feature) {
            $values[$this->driver->name($feature)] = $this->driver->get($feature, $scope);
        }

        return $values;
    }

    /**
     * Retrieve all the features and their values.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values(
            $this->driver->definedFeaturesForScope($this->scope()[0])->all()
        );
    }

    /**
     * Determine if the feature is active.
     */
    public function active(UnitEnum|string $feature): bool
    {
        return $this->allAreActive([$feature]);
    }

    /**
     * Determine if all the features are active.
     *
     * @param array<int, string|UnitEnum> $features
     */
    public function allAreActive(array $features): bool
    {
        $features = $this->normalize($features);

        $this->loadMissingBeforeReading($features);

        foreach ($features as $feature) {
            foreach ($this->scope() as $scope) {
                if ($this->driver->get($feature, $scope) === false) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Determine if any of the features are active.
     *
     * @param array<int, string|UnitEnum> $features
     */
    public function someAreActive(array $features): bool
    {
        $features = $this->normalize($features);

        $this->loadMissingBeforeReading($features);

        foreach ($this->scope() as $scope) {
            foreach ($features as $feature) {
                if ($this->driver->get($feature, $scope) !== false) {
                    continue 2;
                }
            }

            return false;
        }

        return true;
    }

    /**
     * Determine if the feature is inactive.
     */
    public function inactive(UnitEnum|string $feature): bool
    {
        return $this->allAreInactive([$feature]);
    }

    /**
     * Determine if all the features are inactive.
     *
     * @param array<int, string|UnitEnum> $features
     */
    public function allAreInactive(array $features): bool
    {
        $features = $this->normalize($features);

        $this->loadMissingBeforeReading($features);

        foreach ($features as $feature) {
            foreach ($this->scope() as $scope) {
                if ($this->driver->get($feature, $scope) !== false) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Determine if any of the features are inactive.
     *
     * @param array<int, string|UnitEnum> $features
     */
    public function someAreInactive(array $features): bool
    {
        $features = $this->normalize($features);

        $this->loadMissingBeforeReading($features);

        foreach ($this->scope() as $scope) {
            foreach ($features as $feature) {
                if ($this->driver->get($feature, $scope) === false) {
                    continue 2;
                }
            }

            return false;
        }

        return true;
    }

    /**
     * Apply the callback if the feature is active.
     */
    public function when(UnitEnum|string $feature, Closure $whenActive, ?Closure $whenInactive = null): mixed
    {
        if ($this->active($feature)) {
            return $whenActive($this->value($feature), $this);
        }

        if ($whenInactive !== null) {
            return $whenInactive($this);
        }

        return null;
    }

    /**
     * Apply the callback if the feature is inactive.
     */
    public function unless(UnitEnum|string $feature, Closure $whenInactive, ?Closure $whenActive = null): mixed
    {
        return $this->when($feature, $whenActive ?? fn () => null, $whenInactive);
    }

    /**
     * Activate the feature.
     *
     * @param array<int, string|UnitEnum>|string|UnitEnum $feature
     */
    public function activate(UnitEnum|string|array $feature, mixed $value = true): void
    {
        $features = [];

        foreach ($this->normalize($feature) as $name) {
            foreach ($this->scope() as $scope) {
                $features[] = ['feature' => $name, 'scope' => $scope, 'value' => $value];
            }
        }

        $this->driver->setAll($features);
    }

    /**
     * Deactivate the feature.
     *
     * @param array<int, string|UnitEnum>|string|UnitEnum $feature
     */
    public function deactivate(UnitEnum|string|array $feature): void
    {
        $features = [];

        foreach ($this->normalize($feature) as $name) {
            foreach ($this->scope() as $scope) {
                $features[] = ['feature' => $name, 'scope' => $scope, 'value' => false];
            }
        }

        $this->driver->setAll($features);
    }

    /**
     * Forget the flags value.
     *
     * @param array<int, string|UnitEnum>|string|UnitEnum $features
     */
    public function forget(UnitEnum|string|array $features): void
    {
        foreach ($this->normalize($features) as $feature) {
            foreach ($this->scope() as $scope) {
                $this->driver->delete($feature, $scope);
            }
        }
    }

    /**
     * Load the missing values of the given features before they are read one at a time.
     *
     * A single feature and scope is left to the read itself, which loads it the same way.
     *
     * @param array<int, string> $features
     */
    protected function loadMissingBeforeReading(array $features): void
    {
        if (count($features) > 1 || count($this->scope()) > 1) {
            $this->loadMissing($features);
        }
    }

    /**
     * Normalize the given features to their scalar names.
     *
     * @param array<int, string|UnitEnum>|string|UnitEnum $features
     * @return array<int, string>
     */
    protected function normalize(UnitEnum|string|array $features): array
    {
        return array_map(enum_value(...), Arr::wrap($features));
    }

    /**
     * The scope to pass to the driver.
     *
     * @return array<mixed>
     */
    protected function scope(): array
    {
        return $this->scope ?: [null];
    }
}
