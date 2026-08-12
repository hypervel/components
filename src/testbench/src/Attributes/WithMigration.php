<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Attributes;

use Attribute;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Support\Collection;
use Hypervel\Testbench\Contracts\Attributes\Invokable;

use function Hypervel\Testbench\default_migration_path;
use function Hypervel\Testbench\load_migration_paths;

/**
 * Load named Testbench migration sets for the test.
 *
 * The cache, queue, and session aliases resolve to the default Hypervel set.
 * Use TestCase::loadMigrationsFrom() for package or arbitrary migration paths.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class WithMigration implements Invokable
{
    /**
     * @var array<int, string>
     */
    public readonly array $types;

    /**
     * @param string ...$types Named Testbench migration sets to load
     */
    public function __construct(string ...$types)
    {
        $this->types = (new Collection(count($types) > 0 ? $types : ['hypervel']))
            ->transform(static fn (string $type): string => in_array($type, ['cache', 'queue', 'session'], true) ? 'hypervel' : $type)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Handle the attribute.
     */
    public function __invoke(ApplicationContract $app): mixed
    {
        /** @var array<int, string> $paths */
        $paths = (new Collection($this->types))
            ->transform(static fn (string $type): string => default_migration_path($type !== 'hypervel' ? $type : null))
            ->all();

        load_migration_paths($app, $paths);

        return null;
    }
}
