<?php

declare(strict_types=1);

namespace Hypervel\Dispatcher;

class ParsedMiddleware
{
    protected string $name;

    protected array $parameters;

    public function __construct(protected string $signature)
    {
        [$this->name, $this->parameters] = $this->parseMiddleware($signature);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function getSignature(): string
    {
        return $this->signature;
    }

    protected function parseMiddleware(string $middleware): array
    {
        [$name, $parameters] = array_pad(explode(':', $middleware, 2), 2, []);

        if (is_string($parameters)) {
            $parameters = explode(',', $parameters);
        }

        return [$name, $parameters];
    }
}
