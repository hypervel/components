<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Unit;

use Hypervel\Filesystem\Filesystem;
use Hypervel\PHPStan\RuntimeInputsResultCacheValueExtension;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\PHPStan\TestCase;

use function mkdir;
use function symlink;
use function touch;
use function unlink;

class RuntimeInputsResultCacheValueExtensionTest extends TestCase
{
    public function testViewListingsFollowSymlinkedDirectories(): void
    {
        $root = ParallelTesting::tempDir('PHPStanRuntimeInputs');
        (new Filesystem)->deleteDirectory($root);
        mkdir($root . '/views', 0777, true);
        mkdir($root . '/shared/admin', 0777, true);
        touch($root . '/views/welcome.blade.php');
        touch($root . '/shared/admin/dashboard.blade.php');

        try {
            $base = RuntimeInputsResultCacheValueExtension::listingDigest($root . '/views');

            // The view finder finds shared.admin.dashboard through the link.
            symlink($root . '/shared', $root . '/views/shared');
            $linked = RuntimeInputsResultCacheValueExtension::listingDigest($root . '/views');
            $this->assertNotSame($base, $linked);

            touch($root . '/shared/admin/users.blade.php');
            $added = RuntimeInputsResultCacheValueExtension::listingDigest($root . '/views');
            $this->assertNotSame($linked, $added);

            // Another link to the same directory names its views a second way.
            symlink($root . '/shared', $root . '/views/team');
            $this->assertNotSame($added, RuntimeInputsResultCacheValueExtension::listingDigest($root . '/views'));
            unlink($root . '/views/team');
            $this->assertSame($added, RuntimeInputsResultCacheValueExtension::listingDigest($root . '/views'));

            // A link back to a directory it is already inside is not followed again.
            symlink($root . '/views', $root . '/shared/admin/loop');
            $this->assertSame($added, RuntimeInputsResultCacheValueExtension::listingDigest($root . '/views'));

            unlink($root . '/views/shared');
            $this->assertSame($base, RuntimeInputsResultCacheValueExtension::listingDigest($root . '/views'));
        } finally {
            (new Filesystem)->deleteDirectory($root);
        }
    }
}
