<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Casts;

use Hypervel\Contracts\Database\Eloquent\Castable;
use Hypervel\Contracts\Database\Eloquent\CastsAttributes;
use Hypervel\Contracts\Database\Eloquent\ComparesCastableAttributes;
use Hypervel\Contracts\Database\Query\Expression as ExpressionContract;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Query\Expression;
use Hypervel\Database\Query\Grammars\MariaDbGrammar;
use Hypervel\Support\Str;
use InvalidArgumentException;
use JsonException;

class AsVector implements Castable
{
    /**
     * Get the caster class to use when casting from / to this cast target.
     *
     * @return CastsAttributes<array<int, float>, array<int, float>|Arrayable<int, float>>
     */
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class implements CastsAttributes, ComparesCastableAttributes {
            // Eloquent otherwise caches an assigned Arrayable and returns it instead of a float array.
            public bool $withoutObjectCaching = true;

            /**
             * Transform the attribute from the underlying model values.
             *
             * @return null|array<int, float>
             *
             * @throws JsonException
             */
            public function get(Model $model, string $key, mixed $value, array $attributes): ?array
            {
                if ($value === null) {
                    return null;
                }

                $grammar = $model->getConnection()->getQueryGrammar();

                // Decode a MariaDB expression assigned to the model before it is persisted...
                if ($value instanceof ExpressionContract) {
                    $value = Str::between($value->getValue($grammar), "('", "')");

                    return array_map(floatval(...), json_decode($value, true, flags: JSON_THROW_ON_ERROR));
                }

                // MariaDB vector columns return little-endian float32 bytes...
                if ($grammar instanceof MariaDbGrammar) {
                    return array_values(unpack('g*', $value));
                }

                // PostgreSQL (pgvector) returns JSON text...
                return array_map(floatval(...), json_decode($value, true, flags: JSON_THROW_ON_ERROR));
            }

            /**
             * Transform the attribute to its underlying model values.
             *
             * @return array<string, null|Expression|string>
             *
             * @throws InvalidArgumentException
             * @throws JsonException
             */
            public function set(Model $model, string $key, mixed $value, array $attributes): array
            {
                if ($value === null) {
                    return [$key => null];
                }

                if ($value instanceof Arrayable) {
                    $value = $value->toArray();
                }

                if (! is_array($value)) {
                    throw new InvalidArgumentException(
                        sprintf('The [%s] attribute must be an array of floats or an Arrayable instance.', $key)
                    );
                }

                $vector = json_encode(array_values(array_map(floatval(...), $value)), JSON_THROW_ON_ERROR);

                // MariaDB requires vectors to be converted from JSON text server-side...
                return [
                    $key => $model->getConnection()->getQueryGrammar() instanceof MariaDbGrammar
                        ? new Expression("vec_fromtext('{$vector}')")
                        : $vector,
                ];
            }

            /**
             * Determine if the given values are equal.
             *
             * @throws JsonException
             */
            public function compare(Model $model, string $key, mixed $firstValue, mixed $secondValue): bool
            {
                $first = $this->get($model, $key, $firstValue, []);
                $second = $this->get($model, $key, $secondValue, []);

                if ($first === null || $second === null) {
                    return $first === $second;
                }

                // Both supported engines store 32-bit floats, so compare the values as they are stored.
                return pack('g*', ...$first) === pack('g*', ...$second);
            }
        };
    }
}
