<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Methods;

use Hypervel\Http\Request;
use Hypervel\PHPStan\Reflection\ProxiedParameterReflection;
use Hypervel\Validation\ValidationException;
use PHPStan\Reflection\ClassMemberReflection;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\FunctionVariant;
use PHPStan\Reflection\MethodReflection;
use PHPStan\TrinaryLogic;
use PHPStan\Type\ClosureType;
use PHPStan\Type\Generic\TemplateTypeMap;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

use function array_key_exists;

final class Macro implements MethodReflection
{
    /**
     * Whether the macro is called statically.
     */
    private bool $isStatic = false;

    /**
     * Map of macro methods and thrown exception classes.
     *
     * @var string[]
     */
    private array $methodThrowTypeMap = [
        'validate' => ValidationException::class,
        'validateWithBag' => ValidationException::class,
    ];

    /**
     * Create a macro method reflection.
     */
    public function __construct(private ClassReflection $classReflection, private string $methodName, private ClosureType $closureType)
    {
    }

    /**
     * Return the class the macro is registered on.
     */
    public function getDeclaringClass(): ClassReflection
    {
        return $this->classReflection;
    }

    /**
     * Determine whether the macro is private.
     */
    public function isPrivate(): bool
    {
        return false;
    }

    /**
     * Determine whether the macro is public.
     */
    public function isPublic(): bool
    {
        return true;
    }

    /**
     * Determine whether the macro is final.
     */
    public function isFinal(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }

    /**
     * Determine whether the macro is internal.
     */
    public function isInternal(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }

    /**
     * Determine whether the macro is called statically.
     */
    public function isStatic(): bool
    {
        return $this->isStatic;
    }

    /**
     * Set the is static value.
     */
    public function setIsStatic(bool $isStatic): void
    {
        $this->isStatic = $isStatic;
    }

    /**
     * Return the macro docblock.
     */
    public function getDocComment(): ?string
    {
        return null;
    }

    /**
     * Return the macro name.
     */
    public function getName(): string
    {
        return $this->methodName;
    }

    /**
     * Determine whether the macro is deprecated.
     */
    public function isDeprecated(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }

    /**
     * Return the macro prototype.
     */
    public function getPrototype(): ClassMemberReflection
    {
        return $this;
    }

    /**
     * Return the macro's variant, built from its callable.
     */
    public function getVariants(): array
    {
        // Hypervel: Macroable passes the macro its argument values, so a by-reference
        // parameter never receives the caller's variable.
        $parameters = ProxiedParameterReflection::fromTargetParameters($this->closureType->getParameters());

        return [
            new FunctionVariant(TemplateTypeMap::createEmpty(), null, $parameters, $this->closureType->isVariadic(), $this->closureType->getReturnType()),
        ];
    }

    /**
     * Return the macro's deprecation description.
     */
    public function getDeprecatedDescription(): ?string
    {
        return null;
    }

    /**
     * Return the exception type a known framework macro throws.
     */
    public function getThrowType(): ?Type
    {
        // Hypervel: the map describes Request's validation macros; other classes may register macros with the same names.
        if ($this->classReflection->is(Request::class) && array_key_exists($this->methodName, $this->methodThrowTypeMap)) {
            return new ObjectType($this->methodThrowTypeMap[$this->methodName]);
        }

        return null;
    }

    /**
     * Determine whether the macro has side effects.
     */
    public function hasSideEffects(): TrinaryLogic
    {
        return TrinaryLogic::createMaybe();
    }
}
