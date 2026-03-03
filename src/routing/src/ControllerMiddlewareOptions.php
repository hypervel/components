<?php

declare(strict_types=1);

namespace Hypervel\Routing;

class ControllerMiddlewareOptions
{
    /**
     * The middleware options.
     */
    protected array $options;

    /**
     * Create a new middleware option instance.
     */
    public function __construct(array &$options)
    {
        $this->options = &$options;
    }

    /**
     * Set the controller methods the middleware should apply to.
     *
     * @return $this
     */
    public function only(array|string $methods): static
    {
        $this->options['only'] = is_array($methods) ? $methods : func_get_args();

        return $this;
    }

    /**
     * Set the controller methods the middleware should exclude.
     *
     * @return $this
     */
    public function except(array|string $methods): static
    {
        $this->options['except'] = is_array($methods) ? $methods : func_get_args();

        return $this;
    }
}
