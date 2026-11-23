<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use Carbon\Carbon;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\TypeCombinator;

use function sprintf;
use function strtolower;

/**
 * @implements Rule<CallLike>
 *
 * @internal
 */
#[Conditional(attribute: new AutowiredService, if: '%noMutableDates%')]
class NoMutableDatesRule implements Rule
{
    /**
     * The lowercase names of the mutable Carbon classes.
     */
    private const array MUTABLE_CLASSES = [
        'carbon\carbon' => true,
        'hypervel\support\carbon' => true,
    ];

    /**
     * Get the node type the rule checks.
     */
    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * Report the creation of a mutable Carbon date.
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! ($node instanceof New_ || $node instanceof StaticCall)
            || ! $node->class instanceof Name
            || $node->class->isSpecialClassName()
            || ! isset(self::MUTABLE_CLASSES[strtolower($node->class->toString())])
        ) {
            return [];
        }

        $class = $node->class->toString();

        if ($node instanceof New_) {
            $subject = 'new ' . $class;
        } else {
            // Finders such as make() return null when they create nothing; a void method leaves no class at all.
            $type = TypeCombinator::removeFalsey($scope->getType($node));

            if (! $node->name instanceof Identifier
                || $type->getObjectClassNames() === []
                || ! (new ObjectType(Carbon::class))->isSuperTypeOf($type)->yes()
            ) {
                return [];
            }

            $subject = $class . '::' . $node->name->toString() . '()';
        }

        return [
            RuleErrorBuilder::message(sprintf('%s creates a mutable date.', $subject))
                ->identifier('hypervel.mutableDate')
                ->tip('Create dates with the Date facade, the now() and today() helpers, or CarbonImmutable.')
                ->build(),
        ];
    }
}
