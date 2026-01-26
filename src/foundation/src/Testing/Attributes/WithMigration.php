<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Attributes;

use Attribute;
use Hyperf\Database\Migrations\Migrator;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\Contracts\Attributes\Invokable;

/**
 * Loads migration paths for the test.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class WithMigration implements Invokable
{
    /**
     * @var array<int, string>
     */
    public readonly array $paths;

    /**
     * @param string ...$paths Migration paths to load
     */
    public function __construct(string ...$paths)
    {
        $this->paths = $paths;
    }

    /**
     * Handle the attribute.
     */
    public function __invoke(ApplicationContract $app): mixed
    {
        $app->afterResolving(Migrator::class, function (Migrator $migrator) {
            foreach ($this->paths as $path) {
                $migrator->path($path);
            }
        });

        return null;
    }
}
