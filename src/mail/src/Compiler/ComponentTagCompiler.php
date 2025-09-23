<?php

declare(strict_types=1);

namespace Hypervel\Mail\Compiler;

use Hyperf\ViewEngine\Blade;
use Hyperf\ViewEngine\Compiler\ComponentTagCompiler as HyperfComponentTagCompiler;
use Hyperf\ViewEngine\Contract\FactoryInterface;
use InvalidArgumentException;


class ComponentTagCompiler extends HyperfComponentTagCompiler
{
    /**
     * Get the component class for a given component alias.
     */
    public function componentClass(string $component): string
    {
        $viewFactory = Blade::container()->get(FactoryInterface::class);

        if (isset($this->aliases[$component])) {
            if (class_exists($alias = $this->aliases[$component])) {
                return $alias;
            }

            if ($viewFactory->exists($alias)) {
                return $alias;
            }

            throw new InvalidArgumentException(
                "Unable to locate class or view [{$alias}] for component [{$component}]."
            );
        }

        if ($class = $this->findClassByComponent($component)) {
            return $class;
        }

        if ($view = $this->guessComponentFromAutoload($viewFactory, $component)) {
            return $view;
        }

        if (str_starts_with($component, 'mail::')) {
            return $component;
        }

        throw new InvalidArgumentException(
            "Unable to locate a class or view for component [{$component}]."
        );
    }
}
