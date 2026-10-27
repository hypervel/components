<?php

declare(strict_types=1);

// CI installs each split package independently, without the monorepo's root
// dependencies. Explicit versions also work on PR merge refs and feature branches.
$project = $argv[1] ?? throw new InvalidArgumentException('Provide the installation directory.');
$version = $argv[2] ?? throw new InvalidArgumentException('Provide the branch package version, such as 0.4.x-dev.');
$root = dirname(__DIR__, 2);
$versions = [];

foreach (glob($root . '/src/*/composer.json') as $path) {
    $package = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $versions[$package['name']] = $version;
}

$path = $project . '/composer.json';
$manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
$manifest['repositories'] = [
    'components' => [
        'type' => 'path',
        'url' => $root . '/src/*',
        'options' => [
            'symlink' => false,
            'versions' => $versions,
        ],
    ],
    ...($manifest['repositories'] ?? []),
];

if (file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n") === false) {
    throw new RuntimeException("Unable to configure local packages in {$path}");
}

echo "Configured checked-out packages as {$version} in {$path}\n";
