<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use Hypervel\Concurrency\ConcurrencyManager;
use Hypervel\Concurrency\CoroutineDriver;
use Hypervel\Concurrency\ProcessDriver;
use Hypervel\Concurrency\SyncDriver;
use Hypervel\Contracts\Concurrency\Driver;
use Hypervel\PHPStan\Concerns\HasContainer;
use Hypervel\PHPStan\Support\ConfigParser;
use Hypervel\PHPStan\Support\InstanceNames;
use PhpParser\Node\Arg;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

use function count;

/**
 * Types the drivers that the booted Concurrency manager creates.
 *
 * Mirrors MultipleInstanceManager's resolution: `concurrency.driver.{name}` selects the driver when
 * it is present, and otherwise the name itself does. Only the framework's own manager, creating a
 * built-in driver without a custom creator, is known.
 */
#[AutowiredService]
class ConcurrencyDriverHelper
{
    use HasContainer;

    /**
     * The classes of the built-in drivers, keyed by driver name.
     */
    private const array DRIVERS = [
        'coroutine' => CoroutineDriver::class,
        'process' => ProcessDriver::class,
        'sync' => SyncDriver::class,
    ];

    /**
     * Create a new helper instance.
     */
    public function __construct(
        private ConfigParser $configParser,
    ) {
    }

    /**
     * Get the type of the driver a driver() call selects, or null when any instance it can name is unknown.
     *
     * @param DependencyTracker&Scope $scope
     */
    public function getDriverType(?Arg $name, Scope $scope): ?Type
    {
        $names = InstanceNames::fromArgument($name, $scope);

        if ($names === null) {
            return null;
        }

        $types = [];

        foreach ($names as $instance) {
            $class = $this->getDriverClass($instance, $scope);

            if ($class === null) {
                return null;
            }

            $types[] = new ObjectType($class);
        }

        return TypeCombinator::union(...$types);
    }

    /**
     * Get the class of the driver the named instance, or the default instance for null or an empty name, creates when it is known.
     *
     * @param DependencyTracker&Scope $scope
     *
     * @return null|class-string<Driver>
     */
    public function getDriverClass(?string $name, Scope $scope): ?string
    {
        if ($name === null || $name === '') {
            $name = $this->getConfigString('concurrency.default', $scope);
        }

        if ($name === null) {
            return null;
        }

        $instance = $this->configParser->getValue("concurrency.driver.{$name}", $scope);

        if ($instance === null || $instance[0]->maybe()) {
            return null;
        }

        $driver = $instance[0]->yes()
            ? $this->getConfigString("concurrency.driver.{$name}.driver", $scope)
            : $name;

        if ($driver === null || ! isset(self::DRIVERS[$driver])) {
            return null;
        }

        $manager = $this->resolve(ConcurrencyManager::class);

        // A subclass may resolve or create its drivers differently.
        return $manager instanceof ConcurrencyManager
            && $manager::class === ConcurrencyManager::class
            && ! (fn (): bool => isset($this->customCreators[$driver]))->call($manager)
                ? self::DRIVERS[$driver]
                : null;
    }

    /**
     * Get a config value known to be present and a single constant string, or null.
     *
     * @param DependencyTracker&Scope $scope
     */
    private function getConfigString(string $key, Scope $scope): ?string
    {
        $value = $this->configParser->getValue($key, $scope);
        $strings = $value !== null && $value[0]->yes() ? $value[1]->getConstantStrings() : [];

        return count($strings) === 1 ? $strings[0]->getValue() : null;
    }
}
