<?php

declare(strict_types=1);

$vendorDir = dirname(__DIR__);
$baseDir = dirname($vendorDir);
return [
    'Hypervel\Tests\Tinker\Fixtures\App\Foo\TinkerBar' => $baseDir . '/App/Foo/TinkerBar.php',
    'Hypervel\Tests\Tinker\Fixtures\App\Baz\TinkerQux' => $baseDir . '/App/Baz/TinkerQux.php',
    'Hypervel\Tests\Tinker\Fixtures\Vendor\One\Two\TinkerThree' => $vendorDir . '/One/Two/TinkerThree.php',
    'Four\Five\Six' => $vendorDir . '/Four/Five/Six.php',
];
