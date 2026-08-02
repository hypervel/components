<?php

declare(strict_types=1);

namespace Hypervel\Scout\Contracts;

use Hypervel\Scout\Builder;

/**
 * Contract for engines that support deleting documents by filter.
 */
interface DeletesByFilter
{
    /**
     * Delete every document matching the prepared Builder filters.
     *
     * Implementations must run Scout's Builder preparation before compiling
     * filters and complete the remote deletion before returning. An explicit
     * Builder index wins; otherwise deletion targets the model's writable index.
     * A missing target index is a successful no-op.
     */
    public function deleteByFilter(Builder $builder): void;
}
