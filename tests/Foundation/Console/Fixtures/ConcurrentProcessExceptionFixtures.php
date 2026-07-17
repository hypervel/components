<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Console\Fixtures;

use DateTimeImmutable;
use RuntimeException;

class ConcurrentProcessExceptionFixtures
{
    public const PUBLIC_FALSEY_EXCEPTION = PublicFalseyValuesException::class;

    public const OPTIONAL_MESSAGE_EXCEPTION = OptionalMessageException::class;

    public const HIDDEN_OPTIONAL_EXCEPTION = HiddenOptionalException::class;

    public const VARIADIC_EXCEPTION = VariadicException::class;

    public const INHERITED_PUBLIC_EXCEPTION = InheritedPublicException::class;

    public const PUBLIC_VALUE_EXCEPTION = PublicValueException::class;

    public const ZERO_ARGUMENT_EXCEPTION = ZeroArgumentException::class;

    public const TYPED_STORED_VARIADIC_EXCEPTION = TypedStoredVariadicException::class;

    public const UNTYPED_STORED_VARIADIC_EXCEPTION = UntypedStoredVariadicException::class;

    public const MISMATCHED_PUBLIC_PROPERTY_EXCEPTION = MismatchedPublicPropertyException::class;

    /**
     * Throw an exception containing public falsey constructor values.
     */
    public static function throwPublicFalseyValues(): never
    {
        throw new PublicFalseyValuesException(0, false, '', null);
    }

    /**
     * Throw an exception with an inaccessible optional message parameter.
     */
    public static function throwOptionalMessage(): never
    {
        throw new OptionalMessageException('context');
    }

    /**
     * Throw an exception with inaccessible required constructor state.
     */
    public static function throwHiddenRequired(): never
    {
        throw new HiddenRequiredException(7);
    }

    /**
     * Throw an exception with an uninitialized public constructor property.
     */
    public static function throwUninitializedPublicRequired(): never
    {
        throw new UninitializedPublicRequiredException(7);
    }

    /**
     * Throw an exception with inaccessible optional constructor state.
     */
    public static function throwHiddenOptional(): never
    {
        throw new HiddenOptionalException(7);
    }

    /**
     * Throw an exception with an inaccessible variadic constructor tail.
     */
    public static function throwVariadic(): never
    {
        throw new VariadicException('context', 'first', 'second');
    }

    /**
     * Throw a child exception with a reconstructible inherited constructor.
     */
    public static function throwInheritedPublic(): never
    {
        throw new InheritedPublicException(7);
    }

    /**
     * Throw a child exception with an unreconstructible inherited constructor.
     */
    public static function throwInheritedHiddenRequired(): never
    {
        throw new InheritedHiddenRequiredException(7);
    }

    /**
     * Throw an exception with an object-valued public constructor property.
     */
    public static function throwObjectValue(): never
    {
        throw new PublicValueException(new DateTimeImmutable('2026-01-01'));
    }

    /**
     * Throw an exception with nested object state in a public array property.
     */
    public static function throwNestedObjectValue(): never
    {
        throw new PublicValueException([new DateTimeImmutable('2026-01-01')]);
    }

    /**
     * Throw an exception with an enum-valued public constructor property.
     */
    public static function throwEnumValue(): never
    {
        throw new PublicValueException(ConcurrentProcessState::Pending);
    }

    /**
     * Throw an exception with a binary string public constructor property.
     */
    public static function throwBinaryStringValue(): never
    {
        throw new PublicValueException("binary-\xFF");
    }

    /**
     * Throw an exception with a float public constructor property.
     */
    public static function throwFloatValue(): never
    {
        throw new PublicValueException(1.0);
    }

    /**
     * Throw an exception with recursive public constructor state.
     */
    public static function throwRecursiveValue(): never
    {
        $value = [];
        $value['self'] = &$value;

        throw new PublicValueException($value);
    }

    /**
     * Throw an exception with a zero-argument custom constructor.
     */
    public static function throwZeroArgument(): never
    {
        throw new ZeroArgumentException;
    }

    /**
     * Throw an exception that stores typed variadic state in a public property.
     */
    public static function throwTypedStoredVariadic(): never
    {
        throw new TypedStoredVariadicException('first', 'second');
    }

    /**
     * Throw an exception that stores untyped variadic state in a public property.
     */
    public static function throwUntypedStoredVariadic(): never
    {
        throw new UntypedStoredVariadicException('first', 'second');
    }

    /**
     * Throw an exception containing a non-JSON-native constructor value.
     */
    public static function throwResourceState(): never
    {
        throw new ResourceStateException(fopen('php://memory', 'r'));
    }

    /**
     * Throw an exception whose valid PHP class name is not valid UTF-8.
     */
    public static function throwInvalidUtf8ClassName(): never
    {
        $class = "\x80ConcurrentProcessException";

        if (! class_exists($class, false)) {
            eval("class {$class} extends \\RuntimeException {}");
        }

        throw new $class('invalid class name');
    }
}

class PublicFalseyValuesException extends RuntimeException
{
    public function __construct(
        public int $status,
        public bool $retry,
        public string $reason,
        public ?string $detail,
    ) {
        parent::__construct('public falsey values');
    }
}

class OptionalMessageException extends RuntimeException
{
    public function __construct(
        public string $context,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : "context={$context}");
    }
}

class HiddenRequiredException extends RuntimeException
{
    public function __construct(private int $status)
    {
        parent::__construct("status={$status}");
    }
}

class UninitializedPublicRequiredException extends RuntimeException
{
    public int $status;

    public function __construct(int $status)
    {
        parent::__construct("status={$status}");
    }
}

class HiddenOptionalException extends RuntimeException
{
    public function __construct(private int $status = 0)
    {
        parent::__construct("status={$status}");
    }
}

class VariadicException extends RuntimeException
{
    public function __construct(public string $context, string ...$details)
    {
        parent::__construct($context . ':' . implode(',', $details));
    }
}

class InheritedPublicParentException extends RuntimeException
{
    public function __construct(public int $status)
    {
        parent::__construct("status={$status}");
    }
}

class InheritedPublicException extends InheritedPublicParentException
{
}

class InheritedHiddenRequiredParentException extends RuntimeException
{
    public function __construct(private int $status)
    {
        parent::__construct("status={$status}");
    }
}

class InheritedHiddenRequiredException extends InheritedHiddenRequiredParentException
{
}

class PublicValueException extends RuntimeException
{
    public function __construct(public mixed $value)
    {
        parent::__construct('public value');
    }
}

class ZeroArgumentException extends RuntimeException
{
    public int $argumentCount;

    public function __construct()
    {
        $this->argumentCount = func_num_args();

        parent::__construct('zero arguments');
    }
}

class TypedStoredVariadicException extends RuntimeException
{
    /** @var array<int, string> */
    public array $details;

    public function __construct(string ...$details)
    {
        $this->details = $details;

        parent::__construct('count=' . count($details));
    }
}

class UntypedStoredVariadicException extends RuntimeException
{
    /** @var array<int, mixed> */
    public array $details;

    public function __construct(mixed ...$details)
    {
        $this->details = $details;

        parent::__construct('count=' . count($details));
    }
}

class MismatchedPublicPropertyException extends RuntimeException
{
    public string $status;

    public function __construct(int $status)
    {
        $this->status = "v{$status}";

        parent::__construct("status={$status}");
    }
}

class ResourceStateException extends RuntimeException
{
    public function __construct(public mixed $state)
    {
        parent::__construct('resource state');
    }
}

enum ConcurrentProcessState: string
{
    case Pending = 'pending';
}
