<?php

declare(strict_types=1);

use Doctum\Doctum;
use Doctum\Version\GitVersionCollection;
use Symfony\Component\Finder\Finder;

$iterator = Finder::create()
    ->files()
    ->name('*.php')
    ->in(__DIR__ . '/src');

// generate documentation for the 0.4 branch
$versions = GitVersionCollection::create(__DIR__)
    ->add('0.4', 'Hypervel 0.4');

return new Doctum($iterator, [
    'versions' => $versions,
    'title' => 'Hypervel API',
    'base_url' => 'https://api.hypervel.org/', // Necessary to enable the opensearch.xml file generation
    'build_dir' => __DIR__ . '/doctum/build/%version%',
    'cache_dir' => __DIR__ . '/doctum/cache/%version%',
]);
