<?php

declare(strict_types=1);

namespace Hypervel\View\Compilers\Concerns;

use Hyperf\Contract\CanBeEscapedWhenCastToString;
use Hyperf\DbConnection\Model\Model;
use Hypervel\Context\Context;
use Hypervel\Support\Str;
use Hypervel\View\AnonymousComponent;
use Hypervel\View\ComponentAttributeBag;

trait CompilesComponents
{
    /**
     * The component name hash stack.
     */
    protected const COMPONENT_HASH_STACK_CONTEXT_KEY = 'component_hash_stack';

    /**
     * Compile the component statements into valid PHP.
     */
    protected function compileComponent(string $expression): string
    {
        [$component, $alias, $data] = str_contains($expression, ',')
                    ? array_map('trim', explode(',', trim($expression, '()'), 3)) + ['', '', '']
                    : [trim($expression, '()'), '', ''];

        $component = trim($component, '\'"');

        $hash = static::newComponentHash(
            $component === AnonymousComponent::class ? $component.':'.trim($alias, '\'"') : $component
        );

        if (Str::contains($component, ['::class', '\\'])) {
            return static::compileClassComponentOpening($component, $alias, $data, $hash);
        }

        return "<?php \$__env->startComponent{$expression}; ?>";
    }

    /**
     * Get a new component hash for a component name.
     */
    public static function newComponentHash(string $component): string
    {
        $hash = hash('xxh128', $component);

        Context::override(static::COMPONENT_HASH_STACK_CONTEXT_KEY, function ($stack) use ($hash) {
            $stack ??= [];
            $stack[] = $hash;
            return $stack;
        });

        return $hash;
    }

    /**
     * Compile a class component opening.
     */
    public static function compileClassComponentOpening(string $component, string $alias, string $data, string $hash): string
    {
        return implode("\n", [
            '<?php if (isset($component)) { $__componentOriginal'.$hash.' = $component; } ?>',
            '<?php if (isset($attributes)) { $__attributesOriginal'.$hash.' = $attributes; } ?>',
            '<?php $component = '.$component.'::resolve('.($data ?: '[]').' + (isset($attributes) && $attributes instanceof Hypervel\View\ComponentAttributeBag ? $attributes->all() : [])); ?>',
            '<?php $component->withName('.$alias.'); ?>',
            '<?php if ($component->shouldRender()): ?>',
            '<?php $__env->startComponent($component->resolveView(), $component->data()); ?>',
        ]);
    }

    /**
     * Compile the end-component statements into valid PHP.
     */
    protected function compileEndComponent(): string
    {
        return '<?php echo $__env->renderComponent(); ?>';
    }

    /**
     * Compile the end-component statements into valid PHP.
     */
    public function compileEndComponentClass(): string
    {
        $hash = $this->popComponentHashStack();

        return $this->compileEndComponent()."\n".implode("\n", [
            '<?php endif; ?>',
            '<?php if (isset($__attributesOriginal'.$hash.')): ?>',
            '<?php $attributes = $__attributesOriginal'.$hash.'; ?>',
            '<?php unset($__attributesOriginal'.$hash.'); ?>',
            '<?php endif; ?>',
            '<?php if (isset($__componentOriginal'.$hash.')): ?>',
            '<?php $component = $__componentOriginal'.$hash.'; ?>',
            '<?php unset($__componentOriginal'.$hash.'); ?>',
            '<?php endif; ?>',
        ]);
    }

    protected function popComponentHashStack(): string
    {
        $stack = Context::get(static::COMPONENT_HASH_STACK_CONTEXT_KEY, []);

        $hash = array_pop($stack);

        Context::set(static::COMPONENT_HASH_STACK_CONTEXT_KEY, $stack);

        return $hash;
    }

    /**
     * Compile the slot statements into valid PHP.
     */
    protected function compileSlot(string $expression): string
    {
        return "<?php \$__env->slot{$expression}; ?>";
    }

    /**
     * Compile the end-slot statements into valid PHP.
     */
    protected function compileEndSlot(): string
    {
        return '<?php $__env->endSlot(); ?>';
    }

    /**
     * Compile the component-first statements into valid PHP.
     */
    protected function compileComponentFirst(string $expression): string
    {
        return "<?php \$__env->startComponentFirst{$expression}; ?>";
    }

    /**
     * Compile the end-component-first statements into valid PHP.
     */
    protected function compileEndComponentFirst(): string
    {
        return $this->compileEndComponent();
    }

    /**
     * Compile the prop statement into valid PHP.
     */
    protected function compileProps(string $expression): string
    {
        return "<?php \$attributes ??= new \\Hypervel\\View\\ComponentAttributeBag;

\$__newAttributes = [];
\$__propNames = \Hypervel\View\ComponentAttributeBag::extractPropNames({$expression});

foreach (\$attributes->all() as \$__key => \$__value) {
    if (in_array(\$__key, \$__propNames)) {
        \$\$__key = \$\$__key ?? \$__value;
    } else {
        \$__newAttributes[\$__key] = \$__value;
    }
}

\$attributes = new \Hypervel\View\ComponentAttributeBag(\$__newAttributes);

unset(\$__propNames);
unset(\$__newAttributes);

foreach (array_filter({$expression}, 'is_string', ARRAY_FILTER_USE_KEY) as \$__key => \$__value) {
    \$\$__key = \$\$__key ?? \$__value;
}

\$__defined_vars = get_defined_vars();

foreach (\$attributes->all() as \$__key => \$__value) {
    if (array_key_exists(\$__key, \$__defined_vars)) unset(\$\$__key);
}

unset(\$__defined_vars); ?>";
    }

    /**
     * Compile the aware statement into valid PHP.
     */
    protected function compileAware(string $expression): string
    {
        return "<?php foreach ({$expression} as \$__key => \$__value) {
    \$__consumeVariable = is_string(\$__key) ? \$__key : \$__value;
    \$\$__consumeVariable = is_string(\$__key) ? \$__env->getConsumableComponentData(\$__key, \$__value) : \$__env->getConsumableComponentData(\$__value);
} ?>";
    }

    /**
     * Sanitize the given component attribute value.
     */
    public static function sanitizeComponentAttribute(mixed $value): mixed
    {
        if ($value instanceof CanBeEscapedWhenCastToString) {
            return $value->escapeWhenCastingToString();
        }

        return is_string($value) ||
               (is_object($value) && ! $value instanceof Model && ! $value instanceof ComponentAttributeBag && method_exists($value, '__toString'))
                        ? e($value)
                        : $value;
    }
}
