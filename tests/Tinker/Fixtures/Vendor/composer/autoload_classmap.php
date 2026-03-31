<?php

declare(strict_types=1);

$vendorDir = dirname(__DIR__);
$baseDir = dirname($vendorDir);
return [
    'Hypervel\Tests\Tinker\Fixtures\App\Foo\Bar' => $baseDir . '/App/Foo/Bar.php',
    'Hypervel\Tests\Tinker\Fixtures\App\Baz\Qux' => $baseDir . '/App/Baz/Qux.php',
    'Hypervel\Tests\Tinker\Fixtures\Vendor\One\Two\Three' => $vendorDir . '/One/Two/Three.php',
    'Four\Five\Six' => $vendorDir . '/Four/Five/Six.php',
];
