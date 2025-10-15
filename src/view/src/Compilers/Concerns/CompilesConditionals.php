<?php

declare(strict_types=1);

namespace Hypervel\View\Compilers\Concerns;

use Hypervel\Support\Str;

trait CompilesConditionals
{
    /**
     * Identifier for the first case in the switch statement.
     *
     * @var bool
     */
    protected bool $firstCaseInSwitch = true;

    /**
     * Compile the if-auth statements into valid PHP.
     *
     * @param  string|null  $guard
     * @return string
     */
    protected function compileAuth(?string $guard = null): string
    {
        $guard = is_null($guard) ? '()' : $guard;

        return "<?php if(auth()->guard{$guard}->check()): ?>";
    }

    /**
     * Compile the else-auth statements into valid PHP.
     *
     * @param  string|null  $guard
     * @return string
     */
    protected function compileElseAuth(?string $guard = null): string
    {
        $guard = is_null($guard) ? '()' : $guard;

        return "<?php elseif(auth()->guard{$guard}->check()): ?>";
    }

    /**
     * Compile the end-auth statements into valid PHP.
     *
     * @return string
     */
    protected function compileEndAuth(): string
    {
        return '<?php endif; ?>';
    }

    /**
     * Compile the env statements into valid PHP.
     *
     * @param  string  $environments
     * @return string
     */
    protected function compileEnv(string $environments): string
    {
        return "<?php if(app()->environment{$environments}): ?>";
    }

    /**
     * Compile the end-env statements into valid PHP.
     *
     * @return string
     */
    protected function compileEndEnv(): string
    {
        return '<?php endif; ?>';
    }

    /**
     * Compile the production statements into valid PHP.
     *
     * @return string
     */
    protected function compileProduction(): string
    {
        return "<?php if(app()->environment('production')): ?>";
    }

    /**
     * Compile the end-production statements into valid PHP.
     *
     * @return string
     */
    protected function compileEndProduction(): string
    {
        return '<?php endif; ?>';
    }

    /**
     * Compile the if-guest statements into valid PHP.
     *
     * @param  string|null  $guard
     * @return string
     */
    protected function compileGuest(?string $guard = null): string
    {
        $guard = is_null($guard) ? '()' : $guard;

        return "<?php if(auth()->guard{$guard}->guest()): ?>";
    }

    /**
     * Compile the else-guest statements into valid PHP.
     *
     * @param  string|null  $guard
     * @return string
     */
    protected function compileElseGuest(?string $guard = null): string
    {
        $guard = is_null($guard) ? '()' : $guard;

        return "<?php elseif(auth()->guard{$guard}->guest()): ?>";
    }

    /**
     * Compile the end-guest statements into valid PHP.
     *
     * @return string
     */
    protected function compileEndGuest(): string
    {
        return '<?php endif; ?>';
    }

    /**
     * Compile the has-section statements into valid PHP.
     *
     * @param  string  $expression
     * @return string
     */
    protected function compileHasSection(string $expression): string
    {
        return "<?php if (! empty(trim(\$__env->yieldContent{$expression}))): ?>";
    }

    /**
     * Compile the section-missing statements into valid PHP.
     *
     * @param  string  $expression
     * @return string
     */
    protected function compileSectionMissing(string $expression): string
    {
        return "<?php if (empty(trim(\$__env->yieldContent{$expression}))): ?>";
    }

    /**
     * Compile the if statements into valid PHP.
     *
     * @param  string  $expression
     * @return string
     */
    protected function compileIf(string $expression): string
    {
        return "<?php if{$expression}: ?>";
    }

    /**
     * Compile the unless statements into valid PHP.
     *
     * @param  string  $expression
     * @return string
     */
    protected function compileUnless(string $expression): string
    {
        return "<?php if (! {$expression}): ?>";
    }

    /**
     * Compile the else-if statements into valid PHP.
     *
     * @param  string  $expression
     * @return string
     */
    protected function compileElseif(string $expression): string
    {
        return "<?php elseif{$expression}: ?>";
    }

    /**
     * Compile the else statements into valid PHP.
     *
     * @return string
     */
    protected function compileElse(): string
    {
        return '<?php else: ?>';
    }

    /**
     * Compile the end-if statements into valid PHP.
     *
     * @return string
     */
    protected function compileEndif(): string
    {
        return '<?php endif; ?>';
    }

    /**
     * Compile the end-unless statements into valid PHP.
     *
     * @return string
     */
    protected function compileEndunless(): string
    {
        return '<?php endif; ?>';
    }

    /**
     * Compile the if-isset statements into valid PHP.
     *
     * @param  string  $expression
     * @return string
     */
    protected function compileIsset(string $expression): string
    {
        return "<?php if(isset{$expression}): ?>";
    }

    /**
     * Compile the end-isset statements into valid PHP.
     *
     * @return string
     */
    protected function compileEndIsset(): string
    {
        return '<?php endif; ?>';
    }

    /**
     * Compile the switch statements into valid PHP.
     *
     * @param  string  $expression
     * @return string
     */
    protected function compileSwitch(string $expression): string
    {
        $this->firstCaseInSwitch = true;

        return "<?php switch{$expression}:";
    }

    /**
     * Compile the case statements into valid PHP.
     *
     * @param  string  $expression
     * @return string
     */
    protected function compileCase(string $expression): string
    {
        if ($this->firstCaseInSwitch) {
            $this->firstCaseInSwitch = false;

            return "case {$expression}: ?>";
        }

        return "<?php case {$expression}: ?>";
    }

    /**
     * Compile the default statements in switch case into valid PHP.
     *
     * @return string
     */
    protected function compileDefault(): string
    {
        return '<?php default: ?>';
    }

    /**
     * Compile the end switch statements into valid PHP.
     *
     * @return string
     */
    protected function compileEndSwitch(): string
    {
        return '<?php endswitch; ?>';
    }

    /**
     * Compile a once block into valid PHP.
     *
     * @param  string|null  $id
     * @return string
     */
    protected function compileOnce(?string $id = null): string
    {
        $id = $id ? $this->stripParentheses($id) : "'".(string) Str::uuid()."'";

        return '<?php if (! $__env->hasRenderedOnce('.$id.')): $__env->markAsRenderedOnce('.$id.'); ?>';
    }

    /**
     * Compile an end-once block into valid PHP.
     *
     * @return string
     */
    public function compileEndOnce(): string
    {
        return '<?php endif; ?>';
    }

    /**
     * Compile a boolean value into a raw true / false value for embedding into HTML attributes or JavaScript.
     *
     * @param  bool  $condition
     * @return string
     */
    protected function compileBool(bool $condition): string
    {
        return "<?php echo ($condition ? 'true' : 'false'); ?>";
    }

    /**
     * Compile a checked block into valid PHP.
     *
     * @param  string  $condition
     * @return string
     */
    protected function compileChecked(string $condition): string
    {
        return "<?php if{$condition}: echo 'checked'; endif; ?>";
    }

    /**
     * Compile a disabled block into valid PHP.
     *
     * @param  string  $condition
     * @return string
     */
    protected function compileDisabled(string $condition): string
    {
        return "<?php if{$condition}: echo 'disabled'; endif; ?>";
    }

    /**
     * Compile a required block into valid PHP.
     *
     * @param  string  $condition
     * @return string
     */
    protected function compileRequired(string $condition): string
    {
        return "<?php if{$condition}: echo 'required'; endif; ?>";
    }

    /**
     * Compile a readonly block into valid PHP.
     *
     * @param  string  $condition
     * @return string
     */
    protected function compileReadonly(string $condition): string
    {
        return "<?php if{$condition}: echo 'readonly'; endif; ?>";
    }

    /**
     * Compile a selected block into valid PHP.
     *
     * @param  string  $condition
     * @return string
     */
    protected function compileSelected(string $condition): string
    {
        return "<?php if{$condition}: echo 'selected'; endif; ?>";
    }

    /**
     * Compile the push statements into valid PHP.
     *
     * @param  string  $expression
     * @return string
     */
    protected function compilePushIf(string $expression): string
    {
        $parts = explode(',', $this->stripParentheses($expression), 2);

        return "<?php if({$parts[0]}): \$__env->startPush({$parts[1]}); ?>";
    }

    /**
     * Compile the else-if push statements into valid PHP.
     *
     * @param  string  $expression
     * @return string
     */
    protected function compileElsePushIf(string $expression): string
    {
        $parts = explode(',', $this->stripParentheses($expression), 2);

        return "<?php \$__env->stopPush(); elseif({$parts[0]}): \$__env->startPush({$parts[1]}); ?>";
    }

    /**
     * Compile the else push statements into valid PHP.
     *
     * @param  string  $expression
     * @return string
     */
    protected function compileElsePush(string $expression): string
    {
        return "<?php \$__env->stopPush(); else: \$__env->startPush{$expression}; ?>";
    }

    /**
     * Compile the end-push statements into valid PHP.
     *
     * @return string
     */
    protected function compileEndPushIf(): string
    {
        return '<?php $__env->stopPush(); endif; ?>';
    }
}
