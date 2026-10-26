<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Casts;

use Hypervel\Contracts\Database\Eloquent\Castable;
use Hypervel\Contracts\Database\Eloquent\CastsAttributes;
use Hypervel\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\HtmlString;

class AsHtmlString implements Castable
{
    /**
     * Get the caster class to use when casting from / to this cast target.
     *
     * @return CastsAttributes<HtmlString, HtmlString|string>
     */
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class implements CastsAttributes, SerializesCastableAttributes {
            /**
             * Cast the stored value to an HTML string.
             */
            public function get(mixed $model, string $key, mixed $value, array $attributes): ?HtmlString
            {
                return isset($value) ? new HtmlString($value) : null;
            }

            /**
             * Prepare the HTML string for storage.
             */
            public function set(mixed $model, string $key, mixed $value, array $attributes): ?string
            {
                return isset($value) ? (string) $value : null;
            }

            /**
             * Serialize the HTML string for an array or JSON response.
             */
            public function serialize(Model $model, string $key, mixed $value, array $attributes): ?string
            {
                return isset($value) ? (string) $value : null;
            }
        };
    }
}
