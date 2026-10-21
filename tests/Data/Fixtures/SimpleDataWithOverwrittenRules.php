<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures;

class SimpleDataWithOverwrittenRules extends SimpleData
{
    /**
     * Get the validation rules that replace the inherited rules.
     */
    public static function rules(): array
    {
        return [
            'string' => ['string', 'required', 'min:10', 'max:100'],
        ];
    }
}
