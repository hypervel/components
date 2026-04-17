<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use Hypervel\Validation\Enums\SizeMode;

/**
 * Compiled validation plan for a single attribute.
 *
 * Contains pre-resolved flags and the check list (inline + delegated).
 * Immutable after compilation — safe to cache worker-lifetime and share
 * by reference across requests without cloning. Per-request state (like
 * which attributes are excluded) lives on the Validator instance, not here.
 */
final class AttributePlan
{
    public bool $required = false;

    public bool $nullable = false;

    public bool $bail = false;

    public bool $sometimes = false;

    /** Pre-resolved from sibling type rules. Null if ambiguous or no type rule present. */
    public ?SizeMode $sizeMode = null;

    /** Whether any check is an implicit rule (runs even when attribute is absent). */
    public bool $hasImplicitRule = false;

    /** @var list<DelegatedCheck|InlineCheck> */
    public array $checks = [];
}
