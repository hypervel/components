<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Annotations;

use Attribute;
use Hypervel\Mcp\Enums\Role;
use Hypervel\Support\Arr;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_CLASS)]
class Audience extends Annotation
{
    /** @var array<int,string> */
    public array $value;

    /**
     * Create an audience annotation from the intended roles.
     *
     * @param array<int, Role>|Role $roles
     */
    public function __construct(Role|array $roles)
    {
        $roles = Arr::wrap($roles);

        foreach ($roles as $role) {
            if (! $role instanceof Role) {
                throw new InvalidArgumentException(
                    'All values of ' . Audience::class . ' attributes must be instances of ' . Role::class
                );
            }
        }

        $this->value = array_map(fn (Role $role) => $role->value, $roles);
    }

    /**
     * Get the annotation key.
     */
    public function key(): string
    {
        return 'audience';
    }
}
