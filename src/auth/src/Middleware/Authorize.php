<?php

declare(strict_types=1);

namespace Hypervel\Auth\Middleware;

use Closure;
use Hypervel\Auth\Access\AuthorizationException;
use Hypervel\Auth\AuthenticationException;
use Hypervel\Contracts\Auth\Access\Gate;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Http\Request;
use Hypervel\Support\Collection;
use Symfony\Component\HttpFoundation\Response;
use UnitEnum;

use function Hypervel\Support\enum_value;

class Authorize
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(
        protected Gate $gate,
    ) {
    }

    /**
     * Specify the ability and models for the middleware.
     */
    public static function using(UnitEnum|string $ability, string ...$models): string
    {
        return static::class . ':' . implode(',', [enum_value($ability), ...$models]);
    }

    /**
     * Handle an incoming request.
     *
     * @throws AuthenticationException
     * @throws AuthorizationException
     */
    public function handle(Request $request, Closure $next, string $ability, Model|string ...$models): Response
    {
        $this->gate->authorize($ability, $this->getGateArguments($request, $models));

        return $next($request);
    }

    /**
     * Get the arguments parameter for the gate.
     */
    protected function getGateArguments(Request $request, array $models): array
    {
        return (new Collection($models))
            ->map(fn ($model) => $model instanceof Model ? $model : $this->getModel($request, $model))
            ->all();
    }

    /**
     * Get the model to authorize.
     */
    protected function getModel(Request $request, string $model): mixed
    {
        if ($this->isClassName($model)) {
            return trim($model);
        }

        return $request->route($model, null)
            ?? ((preg_match("/^['\"](.*)['\"]$/", trim($model), $matches)) ? $matches[1] : null);
    }

    /**
     * Check if the given string looks like a fully qualified class name.
     */
    protected function isClassName(string $value): bool
    {
        return str_contains($value, '\\');
    }
}
