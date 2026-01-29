<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Attributes;

use Attribute;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\Contracts\Attributes\Invokable;
use Hypervel\Support\Collection;

use function Hypervel\Testbench\default_migration_path;
use function Hypervel\Testbench\load_migration_paths;

/**
 * Loads migration paths for the test.
 *
 * Accepts migration type aliases ('cache', 'queue', 'session', 'laravel') or literal paths.
 * When no arguments are provided, defaults to 'laravel' which loads the standard test migrations.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class WithMigration implements Invokable
{
    /**
     * @var array<int, string>
     */
    public readonly array $types;

    /**
     * @param string ...$types Migration types or paths to load
     */
    public function __construct(string ...$types)
    {
        $this->types = (new Collection(count($types) > 0 ? $types : ['laravel']))
            ->transform(static fn (string $type): string => in_array($type, ['cache', 'queue', 'session']) ? 'laravel' : $type)
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
            ->transform(static fn (string $type): string => default_migration_path($type !== 'laravel' ? $type : null))
            ->all();

        load_migration_paths($app, $paths);

        return null;
    }
}
