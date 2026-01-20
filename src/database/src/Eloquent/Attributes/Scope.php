<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Attributes;

use Attribute;

/**
 * Mark a method as a local query scope.
 *
 * Methods with this attribute can be called as scopes without
 * the traditional 'scope' prefix convention:
 *
 *     #[Scope]
 *     protected function active(Builder $query): void
 *     {
 *         $query->where('active', true);
 *     }
 *
 *     // Called as: User::active() or $query->active()
 */
#[Attribute(Attribute::TARGET_METHOD)]
class Scope
{
    public function __construct()
    {
    }
}
