<?php

declare(strict_types=1);

namespace Hypervel\View\Compilers\Concerns;

use Closure;
use Hypervel\Support\Stringable;

trait CompilesEchos
{
    /**
     * Custom rendering callbacks for stringable objects.
     *
     * @var array<string, callable>
     */
    protected array $echoHandlers = [];

    /**
     * Add a handler to be executed before echoing a given class.
     */
    public function stringable(string|callable $class, ?callable $handler = null): void
    {
        if ($class instanceof Closure) {
            [$class, $handler] = [$this->firstClosureParameterType($class), $class];
        }

        $this->echoHandlers[$class] = $handler;
    }

    /**
     * Compile Blade echos into valid PHP.
     */
    public function compileEchos(string $value): string
    {
        foreach ($this->getEchoMethods() as $method) {
            $value = $this->$method($value);
        }

        return $value;
    }

    /**
     * Get the echo methods in the proper order for compilation.
     *
     * @return array<string>
     */
    protected function getEchoMethods(): array
    {
        return [
            'compileRawEchos',
            'compileEscapedEchos',
            'compileRegularEchos',
        ];
    }

    /**
     * Compile the "raw" echo statements.
     */
    protected function compileRawEchos(string $value): string
    {
        $pattern = sprintf('/(@)?%s\s*(.+?)\s*%s(\r?\n)?/s', $this->rawTags[0], $this->rawTags[1]);

        $callback = function ($matches) {
            $whitespace = empty($matches[3]) ? '' : $matches[3].$matches[3];

            return $matches[1]
                ? substr($matches[0], 1)
                : "<?php echo {$this->wrapInEchoHandler($matches[2])}; ?>{$whitespace}";
        };

        return preg_replace_callback($pattern, $callback, $value);
    }

    /**
     * Compile the "regular" echo statements.
     */
    protected function compileRegularEchos(string $value): string
    {
        $pattern = sprintf('/(@)?%s\s*(.+?)\s*%s(\r?\n)?/s', $this->contentTags[0], $this->contentTags[1]);

        $callback = function ($matches) {
            $whitespace = empty($matches[3]) ? '' : $matches[3].$matches[3];

            $wrapped = sprintf($this->getEchoFormat(), $this->wrapInEchoHandler($matches[2]));

            return $matches[1] ? substr($matches[0], 1) : "<?php echo {$wrapped}; ?>{$whitespace}";
        };

        return preg_replace_callback($pattern, $callback, $value);
    }

    /**
     * Compile the escaped echo statements.
     */
    protected function compileEscapedEchos(string $value): string
    {
        $pattern = sprintf('/(@)?%s\s*(.+?)\s*%s(\r?\n)?/s', $this->escapedTags[0], $this->escapedTags[1]);

        $callback = function ($matches) {
            $whitespace = empty($matches[3]) ? '' : $matches[3].$matches[3];

            return $matches[1]
                ? $matches[0]
                : "<?php echo e({$this->wrapInEchoHandler($matches[2])}); ?>{$whitespace}";
        };

        return preg_replace_callback($pattern, $callback, $value);
    }

    /**
     * Add an instance of the blade echo handler to the start of the compiled string.
     */
    protected function addBladeCompilerVariable(string $result): string
    {
        return "<?php \$__bladeCompiler = app('blade.compiler'); ?>".$result;
    }

    /**
     * Wrap the echoable value in an echo handler if applicable.
     */
    protected function wrapInEchoHandler(string $value): string
    {
        $value = (new Stringable($value))
            ->trim()
            ->when(str_ends_with($value, ';'), function ($str) {
                return $str->beforeLast(';');
            });

        return empty($this->echoHandlers) ? (string) $value : '$__bladeCompiler->applyEchoHandler('.$value.')';
    }

    /**
     * Apply the echo handler for the value if it exists.
     */
    public function applyEchoHandler(mixed $value): mixed
    {
        if (is_object($value) && isset($this->echoHandlers[get_class($value)])) {
            return call_user_func($this->echoHandlers[get_class($value)], $value);
        }

        if (is_iterable($value) && isset($this->echoHandlers['iterable'])) {
            return call_user_func($this->echoHandlers['iterable'], $value);
        }

        return $value;
    }
}
