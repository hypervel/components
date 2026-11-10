<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules\Validation;

use Hypervel\Data\Contracts\ValidateableData;
use Hypervel\Foundation\Http\FormRequest;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Node\MethodReturnStatementsNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

use function strtolower;

/**
 * Recommend arrays over pipe-separated rule strings returned from a form request's or Data class's rules().
 *
 * @implements Rule<MethodReturnStatementsNode>
 */
#[Conditional(attribute: new AutowiredService, if: '%noPipeSeparatedValidationRules%')]
class NoPipeSeparatedValidationRulesReturnRule implements Rule
{
    /**
     * Create a new rule instance.
     */
    public function __construct(private ValidationRuleHelper $helper)
    {
    }

    /**
     * Get the node type the rule checks.
     */
    public function getNodeType(): string
    {
        return MethodReturnStatementsNode::class;
    }

    /**
     * Report the pipe-separated rule strings in the arrays rules() returns.
     *
     * @param MethodReturnStatementsNode $node
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (strtolower($node->getMethodReflection()->getName()) !== 'rules') {
            return [];
        }

        $shape = match (true) {
            $node->getClassReflection()->is(FormRequest::class) => ValidationRuleHelper::MAP,
            $node->getClassReflection()->is(ValidateableData::class) => ValidationRuleHelper::DATA_MAP,
            default => null,
        };

        if ($shape === null) {
            return [];
        }

        $errors = [];

        foreach ($node->getReturnStatements() as $statement) {
            $value = $statement->getReturnNode()->expr;

            if ($value !== null) {
                $errors = [...$errors, ...$this->helper->check($value, $shape, $statement->getScope())];
            }
        }

        return $errors;
    }
}
