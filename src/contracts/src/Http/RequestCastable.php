<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Http;

interface RequestCastable
{
    /**
     * Get the caster to use for validated request input.
     *
     * @param string[] $arguments
     * @return CastsRequestInput|class-string<CastsRequestInput>
     */
    public static function castRequestUsing(array $arguments): CastsRequestInput|string;
}
