<?php

declare(strict_types=1);

namespace Hypervel\Filesystem;

/**
 * Join the given paths together.
 *
 * @param null|string $basePath
 * @param string ...$paths
 */
function join_paths($basePath, ...$paths): string
{
    foreach ($paths as $index => $path) {
        if (empty($path) && $path !== '0') {
            unset($paths[$index]);
        } else {
            $paths[$index] = DIRECTORY_SEPARATOR . ltrim((string) $path, DIRECTORY_SEPARATOR);
        }
    }

    return $basePath . implode('', $paths);
}
