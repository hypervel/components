<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Support;

use PhpParser\Node\Expr;
use PHPStan\Type\Type;

/**
 * A config array whose keys are known, so ConfigParser can compose config sources
 * without typing their values. Each value is another config array, an expression in
 * a config file (typed when a lookup reaches it), a type, or null when unknown.
 *
 * @internal
 */
final readonly class ConfigArray
{
    /**
     * Create a new config array instance.
     *
     * @param array<array-key, null|ConfigArray|Expr|Type> $entries
     * @param array<array-key, true> $optionalKeys the keys that may be absent
     * @param bool $open whether keys besides the entries may be present
     */
    public function __construct(
        public array $entries = [],
        public array $optionalKeys = [],
        public bool $open = false,
    ) {
    }
}
