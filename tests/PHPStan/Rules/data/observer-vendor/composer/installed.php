<?php

declare(strict_types=1);

// acme/ledger stands in for a path repository whose real directory is outside the vendor directory.
return [
    'root' => [
        'name' => 'acme/application',
        'pretty_version' => 'dev-main',
        'version' => 'dev-main',
        'reference' => null,
        'type' => 'project',
        'install_path' => __DIR__ . '/../../',
        'aliases' => [],
        'dev' => true,
    ],
    'versions' => [
        'acme/application' => [
            'pretty_version' => 'dev-main',
            'version' => 'dev-main',
            'reference' => null,
            'type' => 'project',
            'install_path' => __DIR__ . '/../../',
            'aliases' => [],
            'dev_requirement' => false,
        ],
        'acme/audit' => [
            'pretty_version' => '1.0.0',
            'version' => '1.0.0.0',
            'reference' => null,
            'type' => 'library',
            'install_path' => __DIR__ . '/../acme',
            'aliases' => [],
            'dev_requirement' => false,
        ],
        'acme/ledger' => [
            'pretty_version' => 'dev-main',
            'version' => 'dev-main',
            'reference' => null,
            'type' => 'library',
            'install_path' => __DIR__ . '/../../observer-packages/ledger',
            'aliases' => [],
            'dev_requirement' => false,
        ],
    ],
];
