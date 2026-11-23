<?php

namespace ConfigFileResolution;

use function PHPStan\Testing\assertType;

function test(): void
{
    // A key only matches a file whose whole name equals it, never a suffix of it
    assertType("'from app.php'", config('app.v'));
    assertType("'from webapp.php'", config('webapp.v'));
    assertType("'from rcdup.php'", config('rcdup.v'));
    // Unlike upstream, keys that no file holds are absent, so they return null.
    assertType("'from xrcdup.php'", config('xrcdup.v'));
    assertType('null', config('rcapp.v'));

    // Files in subdirectories are keyed by their relative path, like Hypervel does
    assertType("'from nested/rcnest.php'", config('nested.rcnest.v'));
    assertType("'from nested/deep/leaf.php'", config('nested.deep.leaf.v'));
    assertType('int', config('nested.typed.v'));
    assertType('null', config('rcnest.v'));
    assertType('null', config('leaf.v'));

    // Nested files below a key only the framework configuration declares are kept, beside its defaults
    assertType("'from services/stripe.php'", config('services.stripe.v'));
    assertType('array{key: bool|string|null}', config('services.resend'));

    // Hypervel sets nested files over nested.php, so the deepest file wins
    assertType("'from nested.php'", config('nested.v'));
    assertType("'from nested.php'", config('nested.deep.v'));
    assertType("array{v: 'from nested/rcnest.php'}", config('nested.rcnest'));

    // Values that Hypervel merges from several files are not inferred
    assertType('mixed', config('nested'));
    assertType('mixed', config('nested.deep'));

    // Config directories can be glob patterns
    assertType("'from modules/a/config/rcmoda.php'", config('rcmoda.v'));
    assertType("'from modules/b/config/rcmodb.php'", config('rcmodb.v'));

    // A directory that exists is used as is, even if its path has glob characters
    assertType("'from literal[1]/config/rcliteral.php'", config('rcliteral.v'));
}
