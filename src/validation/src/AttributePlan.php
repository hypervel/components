<?php

declare(strict_types=1);

namespace Hypervel\Validation;

/**
 * Compiled validation plan for a single attribute.
 *
 * Contains pre-resolved flags and the check list (inline + delegated).
 * Plans are immutable after compilation and shared by reference across
 * attributes, requests, and concurrent coroutines. Execution and optimizer
 * state must remain on the Validator instance rather than being attached here.
 */
final class AttributePlan
{
    public bool $nullable = false;

    public bool $bail = false;

    public bool $sometimes = false;

    public int $databasePresenceCheckCount = 0;

    /** @var list<DelegatedCheck|InlineCheck> */
    public array $checks = [];
}
