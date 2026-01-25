<?php

declare(strict_types=1);

namespace Hypervel\Mail\Compiler;

use Hypervel\Support\Str;
use Hyperf\ViewEngine\Blade;
use Hyperf\ViewEngine\Compiler\ComponentTagCompiler as HyperfComponentTagCompiler;
use Hyperf\ViewEngine\Contract\FactoryInterface;
use InvalidArgumentException;

class ComponentTagCompiler extends HyperfComponentTagCompiler
{
    /**
     * Compile the slot tags within the given string.
     */
    public function compileSlots(string $value): string
    {
        $pattern = "/
            <
                \\s*
                x[\\-\\:]slot
                (?:\\:(?<inlineName>\\w+(?:-\\w+)*))?
                (?:\\s+name=(?<name>(\"[^\"]+\"|\\\\'[^\\\\']+\\\\'|[^\\s>]+)))?
                (?:\\s+\\:name=(?<boundName>(\"[^\"]+\"|\\\\'[^\\\\']+\\\\'|[^\\s>]+)))?
                (?<attributes>
                    (?:
                        \\s+
                        (?:
                            (?:
                                @(?:class)(\\( (?: (?>[^()]+) | (?-1) )* \\))
                            )
                            |
                            (?:
                                @(?:style)(\\( (?: (?>[^()]+) | (?-1) )* \\))
                            )
                            |
                            (?:
                                \\{\\{\\s*\\\$attributes(?:[^}]+?)?\\s*\\}\\}
                            )
                            |
                            (?:
                                [\\w\\-:.@]+
                                (
                                    =
                                    (?:
                                        \\\"[^\\\"]*\\\"
                                        |
                                        \\'[^\\']*\\'
                                        |
                                        [^\\'\\\"=<>]+
                                    )
                                )?
                            )
                        )
                    )*
                    \\s*
                )
                (?<![\\/=\\-])
            >
        /x";

        $value = preg_replace_callback($pattern, function ($matches) {
            $name = $this->stripQuotes($matches['inlineName'] ?: $matches['name'] ?: $matches['boundName']);

            if (Str::contains($name, '-') && ! empty($matches['inlineName'])) {
                $name = Str::camel($name);
            }

            if (! empty($matches['inlineName']) || ! empty($matches['name'])) {
                $name = "'{$name}'";
            }

            return " @slot({$name}) ";
        }, $value);

        return preg_replace('/<\/\s*x[\-\:]slot[^>]*>/', ' @endslot', $value);
    }

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
