<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Exceptions\Renderer;

use Closure;
use Composer\Autoload\ClassLoader;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Bootstrap\HandleExceptions;
use Hypervel\Http\Request;
use Hypervel\Support\Collection;
use Symfony\Component\ErrorHandler\Exception\FlattenException;

class Exception
{
    /**
     * Create a new exception renderer instance.
     *
     * @param FlattenException $exception The "flattened" exception instance
     * @param Listener $listener The exception listener that collects query data
     */
    public function __construct(
        protected FlattenException $exception,
        protected Request $request,
        protected Listener $listener,
        protected string $basePath,
    ) {
    }

    /**
     * Get the exception title.
     */
    public function title(): string
    {
        return $this->exception->getStatusText();
    }

    /**
     * Get the exception message.
     */
    public function message(): string
    {
        return $this->exception->getMessage();
    }

    /**
     * Get the exception class name.
     */
    public function class(): string
    {
        return $this->exception->getClass();
    }

    /**
     * Get the exception code.
     */
    public function code(): int|string
    {
        return $this->exception->getCode();
    }

    /**
     * Get the HTTP status code.
     */
    public function httpStatusCode(): int
    {
        return $this->exception->getStatusCode();
    }

    /**
     * Get the previous exceptions in the chain.
     *
     * @return Collection<int, static>
     */
    public function previousExceptions(): Collection
    {
        return once(fn () => (new Collection($this->exception->getAllPrevious()))->map(
            fn ($previous) => new static($previous, $this->request, $this->listener, $this->basePath),
        ));
    }

    /**
     * Get the exception's frames.
     *
     * @return Collection<int, Frame>
     */
    public function frames(): Collection
    {
        return once(function () {
            $classMap = array_map(function ($path) {
                return (string) realpath($path);
            }, array_values(ClassLoader::getRegisteredLoaders())[0]->getClassMap());

            $trace = $this->exception->getTrace();

            if (count($trace) > 1 && empty($trace[0]['class']) && empty($trace[0]['function'])) {
                $trace[0]['class'] = $trace[1]['class'] ?? '';
                $trace[0]['type'] = $trace[1]['type'] ?? '';
                $trace[0]['function'] = $trace[1]['function'] ?? '';
                $trace[0]['args'] = $trace[1]['args'] ?? [];
            }

            $trace = array_values(array_filter(
                $trace,
                fn ($trace) => isset($trace['file']),
            ));

            if (($trace[1]['class'] ?? '') === HandleExceptions::class) {
                array_shift($trace);
                array_shift($trace);
            }

            $frames = [];
            $previousFrame = null;

            foreach (array_reverse($trace) as $frameData) {
                $frame = new Frame($this->exception, $classMap, $frameData, $this->basePath, $previousFrame);
                $frames[] = $frame;
                $previousFrame = $frame;
            }

            $frames = array_reverse($frames);

            foreach ($frames as $frame) {
                if (! $frame->isFromVendor()) {
                    $frame->markAsMain();
                    break;
                }
            }

            return new Collection($frames);
        });
    }

    /**
     * Get the exception's frames grouped by vendor status.
     *
     * @return array<int, array{is_vendor: bool, frames: array<int, Frame>}>
     */
    public function frameGroups(): array
    {
        $groups = [];

        foreach ($this->frames() as $frame) {
            $isVendor = $frame->isFromVendor();

            if (empty($groups) || $groups[array_key_last($groups)]['is_vendor'] !== $isVendor) {
                $groups[] = [
                    'is_vendor' => $isVendor,
                    'frames' => [],
                ];
            }

            $groups[array_key_last($groups)]['frames'][] = $frame;
        }

        return $groups;
    }

    /**
     * Get the exception's request instance.
     */
    public function request(): Request
    {
        return $this->request;
    }

    /**
     * Get the request's headers.
     *
     * @return array<string, string>
     */
    public function requestHeaders(): array
    {
        return array_map(function (array $header) {
            return implode(', ', $header);
        }, $this->request()->headers->all());
    }

    /**
     * Get the request's body parameters.
     */
    public function requestBody(): ?string
    {
        if (empty($payload = $this->request()->all())) {
            return null;
        }

        $json = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return str_replace('\\', '', $json);
    }

    /**
     * Get the application's route context.
     *
     * @return array<string, string>
     */
    public function applicationRouteContext(): array
    {
        $route = $this->request()->route();

        return $route ? array_filter([
            'controller' => $route->getActionName(),
            'route name' => $route->getName() ?: null,
            'middleware' => implode(', ', array_map(function ($middleware) {
                return $middleware instanceof Closure ? 'Closure' : $middleware;
            }, $route->gatherMiddleware())),
        ]) : [];
    }

    /**
     * Get the application's route parameters context.
     */
    public function applicationRouteParametersContext(): ?string
    {
        $parameters = $this->request()->route()?->parameters();

        return $parameters ? json_encode(array_map(
            fn ($value) => $value instanceof Model ? $value->withoutRelations() : $value,
            $parameters
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : null;
    }

    /**
     * Get the application's SQL queries.
     *
     * @return array<int, array{connectionName: string, time: float, sql: string}>
     */
    public function applicationQueries(): array
    {
        return array_map(function (array $query) {
            $sql = $query['sql'];

            foreach ($query['bindings'] as $binding) {
                $sql = match (gettype($binding)) {
                    'integer', 'double' => preg_replace('/\?/', (string) $binding, $sql, 1),
                    'NULL' => preg_replace('/\?/', 'NULL', $sql, 1),
                    default => preg_replace('/\?/', "'{$binding}'", $sql, 1),
                };
            }

            return [
                'connectionName' => $query['connectionName'],
                'time' => $query['time'],
                'sql' => $sql,
            ];
        }, $this->listener->queries());
    }
}
