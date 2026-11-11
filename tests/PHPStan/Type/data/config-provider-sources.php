<?php

namespace ConfigProviderSources;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Support\Facades\Config;

use function PHPStan\Testing\assertType;

/**
 * This suite boots a package, whose analysis leaves the values packages merge unknown, since
 * the applications using the package replace them. ConfigCompositionTest checks how an
 * application's analysis composes the same sources.
 */
function test(Repository $config): void
{
    assertType('mixed', config('package.key'));
    assertType('mixed', config('package.env'));
    assertType('mixed', config('property.key'));
    assertType('string', $config->string('package.key'));

    // The Testbench application's files stay known, and a key only a package file sets is unknown rather than absent.
    assertType("array{shared: 'app', app: 'app', ...<int|string, mixed>}", config('merged'));
    assertType("'app'", Config::get('merged.shared'));
    assertType('mixed', config('merged.package'));
    assertType("array{shared: 'app', app: 'app', ...<int|string, mixed>}", config('recursive.nested'));

    // A merge into a framework namespace keeps the framework's entries.
    assertType('bool|string|null', config('hashing.driver'));
    assertType('mixed', config('hashing.package'));

    // array_merge() renumbers integer keys a package may add; array_replace_recursive() keeps the application's.
    assertType('mixed', config('listed'));
    assertType("array{'app-a', ...<int|string, mixed>}", config('replaced'));

    // A package's named entries are unknown too.
    assertType('mixed', config('package.stores.declared'));
}
