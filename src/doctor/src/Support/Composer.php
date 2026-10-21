<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Support;

use Hypervel\Support\Composer as HypervelComposer;

final class Composer
{
    /**
     * Get the Composer command for the application.
     *
     * @return list<string>
     */
    public static function command(): array
    {
        /** @var HypervelComposer $composer */
        $composer = app('composer');

        return array_values($composer->findComposer());
    }
}
