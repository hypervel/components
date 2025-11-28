<?php

declare(strict_types=1);

namespace Hypervel\View;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use Hypervel\View\Compilers\ComponentTagCompiler;

class DynamicComponent extends Component
{
    /**
     * The component tag compiler instance.
     */
    protected static ?ComponentTagCompiler $compiler = null;

    /**
     * The cached component classes.
     */
    protected static array $componentClasses = [];

    /**
     * Create a new component instance.
     */
    public function __construct(
        public string $component
    ) {
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): Closure
    {
        $template = <<<'EOF'
<?php extract((new \Hypervel\Support\Collection($attributes->getAttributes()))->mapWithKeys(function ($value, $key) { return [Hypervel\Support\Str::camel(str_replace([':', '.'], ' ', $key)) => $value]; })->all(), EXTR_SKIP); ?>
{{ props }}
<x-{{ component }} {{ bindings }} {{ attributes }}>
{{ slots }}
{{ defaultSlot }}
</x-{{ component }}>
EOF;

        return function ($data) use ($template) {
            $bindings = $this->bindings($class = $this->classForComponent());

            return str_replace(
                [
                    '{{ component }}',
                    '{{ props }}',
                    '{{ bindings }}',
                    '{{ attributes }}',
                    '{{ slots }}',
                    '{{ defaultSlot }}',
                ],
                [
                    $this->component,
                    $this->compileProps($bindings),
                    $this->compileBindings($bindings),
                    class_exists($class) ? '{{ $attributes }}' : '',
                    $this->compileSlots($data['__laravel_slots']),
                    '{{ $slot ?? "" }}',
                ],
                $template
            );
        };
    }

    /**
     * Compile the @props directive for the component.
     */
    protected function compileProps(array $bindings): string
    {
        if (empty($bindings)) {
            return '';
        }

        return '@props('.'[\''.implode('\',\'', (new Collection($bindings))->map(function ($dataKey) {
            return Str::camel($dataKey);
        })->all()).'\']'.')';
    }

    /**
     * Compile the bindings for the component.
     *
     * @param  array  $bindings
     * @return string
     */
    protected function compileBindings(array $bindings): string
    {
        return (new Collection($bindings))
            ->map(fn ($key) => ':'.$key.'="$'.Str::camel(str_replace([':', '.'], ' ', $key)).'"')
            ->implode(' ');
    }

    /**
     * Compile the slots for the component.
     */
    protected function compileSlots(array $slots): string
    {
        return (new Collection($slots))
            ->map(fn ($slot, $name) => $name === '__default' ? null : '<x-slot name="'.$name.'" '.((string) $slot->attributes).'>{{ $'.$name.' }}</x-slot>')
            ->filter()
            ->implode(PHP_EOL);
    }

    /**
     * Get the class for the current component.
     */
    protected function classForComponent(): string
    {
        if (isset(static::$componentClasses[$this->component])) {
            return static::$componentClasses[$this->component];
        }

        return static::$componentClasses[$this->component] =
                    $this->compiler()->componentClass($this->component);
    }

    /**
     * Get the names of the variables that should be bound to the component.
     */
    protected function bindings(string $class): array
    {
        [$data, $attributes] = $this->compiler()->partitionDataAndAttributes($class, $this->attributes->getAttributes());

        return array_keys($data->all());
    }

    /**
     * Get an instance of the Blade tag compiler.
     */
    protected function compiler(): ComponentTagCompiler
    {
        if (! static::$compiler) {
            static::$compiler = new ComponentTagCompiler(
                Container::getInstance()->make('blade.compiler')->getClassComponentAliases(),
                Container::getInstance()->make('blade.compiler')->getClassComponentNamespaces(),
                Container::getInstance()->make('blade.compiler')
            );
        }

        return static::$compiler;
    }
}
