<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Attributes;

use Attribute;
use Closure;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\Contracts\Attributes\Actionable;
use InvalidArgumentException;

/**
 * Skips the test if the required database driver is not configured.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class RequiresDatabase implements Actionable
{
    /**
     * Determine if currently the default connection.
     */
    public readonly ?bool $default;

    /**
     * @param array<string>|string $driver The required database driver(s)
     * @param null|string $versionRequirement Optional version requirement (e.g., ">=8.0")
     * @param null|string $connection Optional connection name to check
     * @param null|bool $default Whether to check the default connection
     */
    public function __construct(
        public readonly array|string $driver,
        public readonly ?string $versionRequirement = null,
        public readonly ?string $connection = null,
        ?bool $default = null
    ) {
        if ($connection === null && is_string($driver)) {
            $default = true;
        }

        $this->default = $default;

        if (is_array($driver) && $default === true) {
            throw new InvalidArgumentException('Unable to validate default connection when given an array of database drivers');
        }
    }

    /**
     * Handle the attribute.
     *
     * @param Closure(string, array<int, mixed>):void $action
     */
    public function handle(ApplicationContract $app, Closure $action): mixed
    {
        $connection = DB::connection($this->connection);

        if (
            ($this->default ?? false) === true
            && is_string($this->driver)
            && $connection->getDriverName() !== $this->driver
        ) {
            call_user_func($action, 'markTestSkipped', [sprintf('Requires %s to be configured for "%s" database connection', $this->driver, $connection->getName())]);

            return null;
        }

        $drivers = (new Collection(
            Arr::wrap($this->driver)
        ))->filter(fn ($driver) => $driver === $connection->getDriverName());

        if ($drivers->isEmpty()) {
            call_user_func(
                $action,
                'markTestSkipped',
                [sprintf('Requires [%s] to be configured for "%s" database connection', Arr::join(Arr::wrap($this->driver), '/'), $connection->getName())]
            );

            return null;
        }

        if (
            is_string($this->driver)
            && $this->versionRequirement !== null
            && preg_match('/(?P<operator>[<>=!]{0,2})\s*(?P<version>[\d\.-]+(dev|(RC|alpha|beta)[\d\.])?)[ \t]*\r?$/m', $this->versionRequirement, $matches)
        ) {
            if (empty($matches['operator'])) {
                $matches['operator'] = '>=';
            }

            if (! version_compare($connection->getServerVersion(), $matches['version'], $matches['operator'])) {
                call_user_func(
                    $action,
                    'markTestSkipped',
                    [sprintf('Requires %s:%s to be configured for "%s" database connection', $this->driver, $this->versionRequirement, $connection->getName())]
                );
            }
        }

        return null;
    }
}
