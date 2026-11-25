<?php

declare(strict_types=1);

namespace Hypervel\View;

use BackedEnum;
use Closure;
use Hypervel\Container\Container;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use Hypervel\View\Compilers\BladeCompiler;
use Hypervel\View\Compilers\ComponentTagCompiler;
use InvalidArgumentException;

use function Hypervel\Support\enum_value;

class DynamicComponent extends Component
{
    /**
     * The name of the component.
     */
    public string $component;

    /**
     * The component tag compiler instance.
     */
    protected static ?ComponentTagCompiler $compiler = null;

    /**
     * The cached component classes.
     */
    protected static array $componentClasses = [];

    /**
     * The names of the attributes that were bound on the component tag.
     */
    protected array $boundAttributes = [];

    /**
     * The methods that should be ignored.
     */
    protected array $except = ['withBoundAttributes'];

    /**
     * Create a new component instance.
     */
    public function __construct(BackedEnum|string $component)
    {
        $this->component = (string) enum_value($component);

        if (! preg_match('/^[\w\-:.]+$/', $this->component)) {
            throw new InvalidArgumentException("Invalid component name [{$this->component}].");
        }
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

            foreach ($bindings as $key) {
                if (! preg_match('/^[\w\-:.]+$/', $key)) {
                    throw new InvalidArgumentException("Invalid attribute name [{$key}] for component [{$this->component}].");
                }
            }

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
                    $this->compileSlots($data['__hypervel_slots']),
                    '{{ $slot ?? "" }}',
                ],
                $template
            );
        };
    }

    /**
     * Set the names of the attributes that were bound on the component tag.
     *
     * @return $this
     */
    public function withBoundAttributes(array $attributes): static
    {
        $this->boundAttributes = $attributes;

        return $this;
    }

    /**
     * Set the extra attributes that the component should make available.
     *
     * @return $this
     */
    public function withAttributes(array $attributes): static
    {
        if ($this->boundAttributes && class_exists($class = $this->classForComponent())) {
            [$data] = $this->compiler()->partitionDataAndAttributes($class, $attributes);

            foreach ($this->boundAttributes as $key) {
                if (array_key_exists($key, $attributes) && ! $data->has($key)) {
                    $attributes[$key] = BladeCompiler::sanitizeComponentAttribute($attributes[$key]);
                }
            }
        }

        return parent::withAttributes($attributes);
    }

    /**
     * Compile the @props directive for the component.
     */
    protected function compileProps(array $bindings): string
    {
        if ($bindings === []) {
            return '';
        }

        return '@props([\'' . implode('\',\'', (new Collection($bindings))->map(function ($dataKey) {
            return Str::camel($dataKey);
        })->all()) . '\'])';
    }

    /**
     * Compile the bindings for the component.
     */
    protected function compileBindings(array $bindings): string
    {
        return (new Collection($bindings))
            ->map(fn ($key) => ':' . $key . '="$' . Str::camel(str_replace([':', '.'], ' ', $key)) . '"')
            ->implode(' ');
    }

    /**
     * Compile the slots for the component.
     */
    protected function compileSlots(array $slots): string
    {
        return (new Collection($slots))
            ->reject(fn ($slot, $name) => $name === '__default')
            ->each(function ($slot, $name) {
                if (! preg_match('/^[a-zA-Z_]\w*$/', $name)) {
                    throw new InvalidArgumentException("Invalid slot name [{$name}] for component [{$this->component}].");
                }
            })
            ->map(fn ($slot, $name) => '<x-slot name="' . $name . '" :attributes="$' . $name . '->attributes">{{ $' . $name . ' }}</x-slot>')
            ->implode(PHP_EOL);
    }

    /**
     * Get the class for the current component.
     */
    protected function classForComponent(): string
    {
        return static::$componentClasses[$this->component] ?? static::$componentClasses[$this->component]
                    = $this->compiler()->componentClass($this->component);
    }

    /**
     * Get the names of the variables that should be bound to the component.
     */
    protected function bindings(string $class): array
    {
        [$data] = $this->compiler()->partitionDataAndAttributes($class, $this->attributes->getAttributes());

        return array_keys($data->all());
    }

    /**
     * Get an instance of the Blade tag compiler.
     */
    protected function compiler(): ComponentTagCompiler
    {
        if (! static::$compiler) {
            $bladeCompiler = Container::getInstance()->make('blade.compiler');

            static::$compiler = new ComponentTagCompiler(
                $bladeCompiler->getClassComponentAliases(),
                $bladeCompiler->getClassComponentNamespaces(),
                $bladeCompiler
            );
        }

        return static::$compiler;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$compiler = null;
        static::$componentClasses = [];
    }
}
