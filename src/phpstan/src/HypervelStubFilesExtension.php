<?php

declare(strict_types=1);

namespace Hypervel\PHPStan;

use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\PhpDoc\StubFilesExtension;
use Symfony\Component\Finder\Finder;

#[AutowiredService]
final class HypervelStubFilesExtension implements StubFilesExtension
{
    /**
     * Get the stub files to load.
     *
     * Upstream selects stub directories by Laravel version and Redis stubs by client,
     * configured through its redisDriver parameter. Hypervel has one supported
     * framework version and only the phpredis client, so every stub applies and
     * that parameter is omitted.
     */
    public function getFiles(): array
    {
        $files = [];

        foreach (Finder::create()->files()->name('*.stub')->in(__DIR__ . '/../stubs/common') as $stubFile) {
            $files[] = $stubFile->getRealPath();
        }

        return $files;
    }
}
