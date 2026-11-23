<?php

declare(strict_types=1);

// Disks for the storage disk typing fixtures, merged with the framework's own disks.
return [
    'disks' => [
        'ftp' => ['driver' => 'ftp', 'host' => 'localhost'],
        'custom' => ['driver' => 'custom'],
        'pooled-custom' => ['driver' => 'pooled-custom'],
        'scoped' => ['driver' => 'scoped', 'disk' => 's3', 'prefix' => 'tenant'],
        'unsupported' => ['driver' => 'unsupported'],
        'replaced' => ['driver' => 'local', 'root' => '/tmp'],
    ],
];
