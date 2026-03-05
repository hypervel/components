<?php

declare(strict_types=1);

namespace Hypervel\Tests\Di\Stub\Psr4Only;

/**
 * This class exists solely to test PSR-4 resolution in buildClassMap().
 *
 * It lives under a namespace that is NOT registered in composer.json's
 * autoload or autoload-dev, so it will never appear in Composer's static
 * class map — even with an optimized autoloader. Tests must register
 * the PSR-4 prefix manually via addPsr4() before using it.
 */
class UnmappedService
{
    public function handle(): string
    {
        return 'handled';
    }
}
