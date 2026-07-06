<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation;

use Hypervel\Filesystem\Filesystem;

use function Hypervel\Testbench\join_paths;
use function Hypervel\Testbench\testbench_path;

class EnvironmentFile
{
    /**
     * Create a new environment file resolver.
     */
    public function __construct(
        protected Filesystem $filesystem
    ) {
    }

    /**
     * Resolve the package or workbench environment file.
     */
    public function package(string $workingPath, string $filename = '.env'): ?string
    {
        $sourcePath = $this->sourcePath($workingPath);
        $environmentPath = $this->filesystem->isDirectory(join_paths($sourcePath, 'workbench'))
            ? join_paths($sourcePath, 'workbench')
            : $sourcePath;

        return $this->firstExisting($environmentPath, $this->candidateNames($filename));
    }

    /**
     * Resolve the package or workbench environment file, falling back to the skeleton example.
     */
    public function packageOrSkeletonFallback(string $workingPath, string $appBasePath, string $filename = '.env'): ?string
    {
        return $this->package($workingPath, $filename)
            ?? $this->skeletonFallback($appBasePath);
    }

    /**
     * Resolve the source path for testbench config and workbench fixtures.
     */
    public function sourcePath(string $workingPath): string
    {
        foreach (['testbench.yaml', 'testbench.yaml.example', 'testbench.yaml.dist'] as $configurationFile) {
            if ($this->filesystem->isFile(join_paths($workingPath, $configurationFile))) {
                return $workingPath;
            }
        }

        if ($this->filesystem->isDirectory(join_paths($workingPath, 'workbench'))) {
            return $workingPath;
        }

        return testbench_path();
    }

    /**
     * Resolve the first existing file from the ordered candidate list.
     *
     * @param array<int, string> $candidates
     */
    protected function firstExisting(string $path, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $file = join_paths($path, $candidate);

            if ($this->filesystem->isFile($file)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Get the ordered environment file candidate names.
     *
     * @return array<int, string>
     */
    protected function candidateNames(string $filename): array
    {
        return array_values(array_unique([
            $filename,
            "{$filename}.example",
            "{$filename}.dist",
            '.env',
            '.env.example',
            '.env.dist',
        ]));
    }

    /**
     * Resolve the skeleton environment fallback.
     */
    protected function skeletonFallback(string $appBasePath): ?string
    {
        $file = join_paths($appBasePath, '.env.example');

        return $this->filesystem->isFile($file) ? $file : null;
    }
}
