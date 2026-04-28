<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hypervel\Contracts\Auth\Access\Gate as GateContract;

/**
 * @method static bool has(\UnitEnum|array|string $ability)
 * @method static \Hypervel\Auth\Access\Response allowIf(mixed $condition, string|null $message = null, string|null $code = null)
 * @method static \Hypervel\Auth\Access\Response denyIf(mixed $condition, string|null $message = null, string|null $code = null)
 * @method static \Hypervel\Auth\Access\Gate define(\UnitEnum|string $ability, callable|array|string $callback)
 * @method static \Hypervel\Auth\Access\Gate resource(string $name, string $class, array|null $abilities = null)
 * @method static \Hypervel\Auth\Access\Gate policy(string $class, string $policy)
 * @method static \Hypervel\Auth\Access\Gate before(callable $callback)
 * @method static \Hypervel\Auth\Access\Gate after(callable $callback)
 * @method static bool allows(\Traversable|\UnitEnum|array|string $ability, mixed $arguments = [])
 * @method static bool denies(\Traversable|\UnitEnum|array|string $ability, mixed $arguments = [])
 * @method static bool check(\Traversable|\UnitEnum|array|string $abilities, mixed $arguments = [])
 * @method static bool any(\Traversable|\UnitEnum|array|string $abilities, mixed $arguments = [])
 * @method static bool none(\Traversable|\UnitEnum|array|string $abilities, mixed $arguments = [])
 * @method static \Hypervel\Auth\Access\Response authorize(\UnitEnum|string $ability, mixed $arguments = [])
 * @method static \Hypervel\Auth\Access\Response inspect(\UnitEnum|string $ability, mixed $arguments = [])
 * @method static mixed raw(string $ability, mixed $arguments = [])
 * @method static mixed getPolicyFor(object|string $class)
 * @method static \Hypervel\Auth\Access\Gate guessPolicyNamesUsing(callable $callback)
 * @method static mixed resolvePolicy(object|string $class)
 * @method static \Hypervel\Auth\Access\Gate forUser(mixed $user)
 * @method static array abilities()
 * @method static array policies()
 * @method static \Hypervel\Auth\Access\Gate defaultDenialResponse(\Hypervel\Auth\Access\Response $response)
 * @method static \Hypervel\Auth\Access\Gate setContainer(\Hypervel\Contracts\Container\Container $container)
 * @method static \Hypervel\Database\Eloquent\Builder scope(string $ability, \Hypervel\Database\Eloquent\Builder $query)
 * @method static \Hypervel\Database\Query\Expression select(string $ability, \Hypervel\Database\Eloquent\Builder|string|\Hypervel\Database\Eloquent\Model $query)
 * @method static void flushState()
 * @method static \Hypervel\Auth\Access\Response denyWithStatus(int $status, string|null $message = null, string|int|null $code = null)
 * @method static \Hypervel\Auth\Access\Response denyAsNotFound(string|null $message = null, string|int|null $code = null)
 *
 * @see \Hypervel\Auth\Access\Gate
 */
class Gate extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return GateContract::class;
    }
}
