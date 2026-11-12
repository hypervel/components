<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use Hypervel\PHPStan\Reflection\SourceFiles;
use Hypervel\PHPStan\Support\FacadeHelper;
use Hypervel\Support\Attributes\Unsupported;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\AttributeReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function array_find;
use function array_values;
use function count;
use function sprintf;

/**
 * Reports calls that reach a method marked #[Unsupported], which always throws.
 *
 * The attribute's message goes in the error message rather than a tip, because several output
 * formats drop tips.
 *
 * @implements Rule<CallLike>
 *
 * @internal
 */
#[AutowiredService]
class UnsupportedCallRule implements Rule
{
    /**
     * Create a new unsupported call rule instance.
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        private FacadeHelper $facadeHelper,
    ) {
    }

    /**
     * Get the node type the rule checks.
     */
    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * Report each unsupported method the call can run.
     *
     * @param CallLike $node
     * @param DependencyTracker&Scope $scope
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        // PHPStan also analyzes a nullsafe call as a method call.
        if (! ($node instanceof MethodCall || $node instanceof StaticCall)
            || ! $node->name instanceof Identifier
            || $node->isFirstClassCallable()) {
            return [];
        }

        $errors = [];

        foreach ($this->facadeHelper->getCalledMethods($node, $node->name->toString(), $scope) as $method) {
            $attribute = $this->findUnsupported($method->getAttributes());
            $declaration = $method->getDeclaringClass()->getName() . '::' . $method->getName();

            if ($attribute === null || isset($errors[$declaration])) {
                continue;
            }

            // An unsupported method may reject its call through another one, as a stream's rewind() calls seek().
            if ($this->findUnsupported($scope->getFunction()?->getAttributes() ?? []) !== null) {
                return [];
            }

            // Exported nodes keep the attribute's argument expressions, not the values of constants they read.
            foreach (SourceFiles::forAttributeArguments($this->reflectionProvider, $method->getDeclaringClass(), Unsupported::class, $method->getName()) as $file) {
                if ($file !== $scope->getFile()) {
                    $scope->trackFileDependency($file);
                }
            }

            $message = sprintf('%s() is not supported by this implementation and always throws.', $declaration);
            $guidance = ($attribute->getArgumentTypes()['message'] ?? null)?->getConstantStrings() ?? [];

            if (count($guidance) === 1 && $guidance[0]->getValue() !== '') {
                $message .= "\n" . $guidance[0]->getValue();
            }

            $errors[$declaration] = RuleErrorBuilder::message($message)->identifier('hypervel.unsupportedCall')->build();
        }

        return array_values($errors);
    }

    /**
     * Find the #[Unsupported] attribute among a function's attributes.
     *
     * @param list<AttributeReflection> $attributes
     */
    private function findUnsupported(array $attributes): ?AttributeReflection
    {
        return array_find($attributes, static fn (AttributeReflection $attribute): bool => $attribute->getName() === Unsupported::class);
    }
}
