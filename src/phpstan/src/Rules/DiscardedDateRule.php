<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTime;
use DateTimeImmutable;
use Hypervel\Support\Carbon as HypervelCarbon;
use Hypervel\Support\CarbonImmutable as HypervelCarbonImmutable;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Expression;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\TrinaryLogic;
use PHPStan\Type\TypeCombinator;

use function array_all;
use function array_any;
use function array_key_exists;
use function preg_match;
use function sprintf;
use function strtolower;

/**
 * Reports a Carbon date change whose result is discarded, which leaves an immutable date unchanged.
 *
 * @implements Rule<Expression>
 *
 * @internal
 */
#[Conditional(attribute: new AutowiredService, if: '%checkDiscardedDates%')]
class DiscardedDateRule implements Rule
{
    /**
     * The classes whose native methods and @method tags are Carbon's own date methods.
     */
    private const array DATE_CLASSES = [
        CarbonInterface::class => true,
        CarbonImmutable::class => true,
        Carbon::class => true,
        HypervelCarbonImmutable::class => true,
        HypervelCarbon::class => true,
    ];

    /**
     * The lowercase names of the date modifiers outside MODIFIER_PATTERN.
     */
    private const array MODIFIERS = [
        'average' => true,
        'change' => true,
        'midday' => true,
        'minus' => true,
        'modify' => true,
        'plus' => true,
        'rawadd' => true,
        'rawsub' => true,
        'set' => true,
        'setanchorday' => true,
        'setdate' => true,
        'setdatefrom' => true,
        'setdatetime' => true,
        'setdatetimefrom' => true,
        'setdaysfromstartofweek' => true,
        'setisodate' => true,
        'settime' => true,
        'settimefrom' => true,
        'settimefromtimestring' => true,
        'settimestamp' => true,
        'settimezone' => true,
        'setunit' => true,
        'setunitnooverflow' => true,
        'shifttimezone' => true,
        'timestamp' => true,
        'timezone' => true,
        'utc' => true,
    ];

    /**
     * Match the lowercase names of the modifier families, and of each unit setter in its plain,
     * plural, set and set-plural forms.
     */
    private const string MODIFIER_PATTERN = '/^(?:add|sub|startof|endof|next|previous|nth|firstof|lastof|round|floor|ceil)|^(?:set)?(?:year|month|day|hour|minute|second|milli|millisecond|micro|microsecond)s?$/';

    /**
     * The lowercase names of the methods that get a value without an argument and set it with one.
     */
    private const array GETTER_SETTERS = [
        'dayofyear' => true,
        'isoweek' => true,
        'isoweekday' => true,
        'isoweekyear' => true,
        'tz' => true,
        'utcoffset' => true,
        'week' => true,
        'weekday' => true,
        'weekyear' => true,
    ];

    /**
     * Match Carbon's unit-of-unit getter/setters, such as dayOfWeek() and weekOfYear().
     *
     * Carbon dispatches these names case-sensitively, so this pattern does too.
     */
    private const string UNIT_OF_UNIT_PATTERN = '/^[a-z]+Of[A-Z][a-z]+$/';

    /**
     * Get the node type the rule checks.
     */
    public function getNodeType(): string
    {
        return Expression::class;
    }

    /**
     * Report a discarded date change.
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $call = $node->expr;

        if (! ($call instanceof MethodCall || $call instanceof NullsafeMethodCall)
            || ! $call->name instanceof Identifier
            || $call->isFirstClassCallable()) {
            return [];
        }

        $name = $call->name->toString();
        $lowerName = strtolower($name);
        $modifier = isset(self::MODIFIERS[$lowerName]) || preg_match(self::MODIFIER_PATTERN, $lowerName) === 1;

        if (! $modifier && ! isset(self::GETTER_SETTERS[$lowerName]) && preg_match(self::UNIT_OF_UNIT_PATTERN, $name) !== 1) {
            return [];
        }

        $receiverType = $scope->getType($call->var);

        if ($call instanceof NullsafeMethodCall) {
            $receiverType = TypeCombinator::removeNull($receiverType);
        }

        $classes = $receiverType->getObjectClassReflections();

        if ($classes === [] || ! array_all($classes, fn (ClassReflection $class): bool => $this->declaresDateMethod($class, $name))) {
            return [];
        }

        $immutable = TrinaryLogic::lazyExtremeIdentity($classes, static fn (ClassReflection $class): TrinaryLogic => match (true) {
            $class->is(DateTimeImmutable::class) => TrinaryLogic::createYes(),
            $class->is(DateTime::class) => TrinaryLogic::createNo(),
            default => TrinaryLogic::createMaybe(),
        });
        $changes = $modifier ? TrinaryLogic::createYes() : $this->setsValue($call, $classes[0], $name, $scope);
        $result = $immutable->and($changes);

        if ($result->no()) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                $result->yes()
                    ? 'The result of %s() is discarded, so the immutable date is unchanged.'
                    : 'The result of %s() is discarded, so any change it makes to an immutable date is lost.',
                $name,
            ))
                ->identifier('hypervel.discardedDate')
                ->tip("Immutable date methods return a new date, so assign the result where it's needed.")
                ->build(),
        ];
    }

    /**
     * Determine whether Carbon declares the method for the class, natively or by an @method tag.
     *
     * A user override is judged by its own declaration. Carbon runs its tagged methods before any
     * macro of the same name, so a tagged method is never a macro.
     */
    private function declaresDateMethod(ClassReflection $class, string $name): bool
    {
        if ($class->hasNativeMethod($name)) {
            return isset(self::DATE_CLASSES[$class->getNativeMethod($name)->getDeclaringClass()->getName()]);
        }

        return array_any(
            $class->getAncestors(),
            static fn (ClassReflection $ancestor): bool => isset(self::DATE_CLASSES[$ancestor->getName()])
                && array_key_exists($name, $ancestor->getMethodTags()),
        );
    }

    /**
     * Determine whether a getter/setter call sets a value: its value argument is present and not null.
     *
     * An unpacked argument may supply the value.
     */
    private function setsValue(MethodCall|NullsafeMethodCall $call, ClassReflection $class, string $name, Scope $scope): TrinaryLogic
    {
        // A named argument binds to the native parameter, which PHPStan renames when it merges an @method
        // tag into the method, as Carbon's Week trait does for dayOfYear().
        $method = $class->hasNativeMethod($name) ? $class->getNativeMethod($name) : $class->getMethod($name, $scope);
        $argument = $call->getArg($method->getOnlyVariant()->getParameters()[0]->getName(), 0);

        if ($argument === null) {
            return array_any($call->getArgs(), static fn (Arg $argument): bool => $argument->unpack)
                ? TrinaryLogic::createMaybe()
                : TrinaryLogic::createNo();
        }

        return $scope->getType($argument->value)->isNull()->negate();
    }
}
