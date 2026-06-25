<?php

declare(strict_types=1);

namespace Hypervel\Permission;

use Closure;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Permission\Contracts\Wildcard;
use Hypervel\Permission\Exceptions\WildcardPermissionNotProperlyFormatted;
use Hypervel\Support\Str;

class WildcardPermission implements Wildcard
{
    public const WILDCARD_TOKEN = '*';

    public const PART_DELIMITER = '.';

    public const SUBPART_DELIMITER = ',';

    /**
     * Create a new wildcard permission matcher.
     */
    public function __construct(protected Model $record)
    {
    }

    /**
     * Get the wildcard permission index.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getIndex(): array
    {
        $index = [];

        $getAllPermissions = Closure::fromCallable([$this->record, 'getAllPermissions']);

        foreach ($getAllPermissions() as $permission) {
            $index[$permission->guard_name] = $this->buildIndex(
                $index[$permission->guard_name] ?? [],
                explode(static::PART_DELIMITER, $permission->name),
                $permission->name,
            );
        }

        return $index;
    }

    /**
     * Build the wildcard permission index.
     *
     * @param array<string, mixed> $index
     * @param array<int, string> $parts
     * @return array<string, mixed>
     */
    protected function buildIndex(array $index, array $parts, string $permission): array
    {
        if ($parts === []) {
            $index[''] = true;

            return $index;
        }

        $part = array_shift($parts);

        if (blank($part)) {
            throw WildcardPermissionNotProperlyFormatted::create($permission);
        }

        if (! Str::contains($part, static::SUBPART_DELIMITER)) {
            $index[$part] = $this->buildIndex(
                $index[$part] ?? [],
                $parts,
                $permission,
            );
        }

        $subParts = explode(static::SUBPART_DELIMITER, $part);

        foreach ($subParts as $subPart) {
            if (blank($subPart)) {
                throw WildcardPermissionNotProperlyFormatted::create($permission);
            }

            $index[$subPart] = $this->buildIndex(
                $index[$subPart] ?? [],
                $parts,
                $permission,
            );
        }

        return $index;
    }

    /**
     * Determine if the wildcard permission implies another permission.
     *
     * @param array<string, array<string, mixed>> $index
     */
    public function implies(string $permission, string $guardName, array $index): bool
    {
        if (! array_key_exists($guardName, $index)) {
            return false;
        }

        $permission = explode(static::PART_DELIMITER, $permission);

        return $this->checkIndex($permission, $index[$guardName]);
    }

    /**
     * Check the permission against the wildcard index.
     *
     * @param array<int, string> $permission
     * @param array<string, mixed> $index
     */
    protected function checkIndex(array $permission, array $index): bool
    {
        if (array_key_exists(strval(null), $index)) {
            return true;
        }

        if (empty($permission)) {
            return false;
        }

        $firstPermission = array_shift($permission);

        if (
            array_key_exists($firstPermission, $index)
            && $this->checkIndex($permission, $index[$firstPermission])
        ) {
            return true;
        }

        if (array_key_exists(static::WILDCARD_TOKEN, $index)) {
            return $this->checkIndex($permission, $index[static::WILDCARD_TOKEN]);
        }

        return false;
    }
}
