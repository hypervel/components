<?php

namespace ConfigProviderSources;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Support\Facades\Config;

use function PHPStan\Testing\assertType;

function test(Repository $config): void
{
    // Definite merges, including keys resolved from a class constant and a static property.
    assertType("'package'", config('package.key'));
    assertType('bool|string|null', config('package.env'));
    assertType("'property'", config('property.key'));

    // The application file's values replace the package file's; a dynamic name writes below merged.disks.
    assertType("array{shared: 'app', package: 'package', app: 'app', disks?: mixed}", config('merged'));
    assertType("array{shared: 'app', package: 'package', app: 'app'}", config('recursive.nested'));

    // array_merge() appends and renumbers integer keys; array_replace_recursive() replaces by key.
    assertType("array{'package-a', 'package-b', 'app-a'}", config('listed'));
    assertType("array{'app-a', 'package-b'}", config('replaced'));

    // An application shape with unknown keys may replace any package value it does not list.
    assertType('string', config('open.shared'));
    assertType('mixed', config('open.enabled'));
    assertType('string', config('open-recursive.shared'));
    assertType('mixed', config('open-recursive.enabled'));

    // Providers merge package files in an order the analysis does not follow, so their reads are unknown.
    assertType('mixed', config('package.url'));

    // Mergeable options take named entries that no file declares.
    assertType("array{driver: 'array'}", config('package.stores.declared'));
    assertType('mixed', config('package.stores.undeclared'));
    assertType('mixed', config('logging.channels.undeclared'));

    // Written keys, and merges that may not run or whose order is unknown.
    assertType('mixed', config('package.written'));
    assertType('mixed', config('package.helper'));
    assertType('mixed', config('package.direct'));
    assertType('mixed', config('package.web'));
    assertType('mixed', config('package.parameter'));
    assertType('mixed', config('overlap.key'));
    assertType('mixed', config('conditional.key'));
    assertType('mixed', config('after-return.key'));

    // A dynamic key is bounded by its complete constant segments, so a partly constant
    // segment leaves its whole parent unknown.
    assertType('mixed', config('merged.disks.local'));
    assertType('mixed', config('partial.other'));

    assertType("'package'", $config->string('package.key'));
    assertType("'app'", Config::get('merged.shared'));
}
