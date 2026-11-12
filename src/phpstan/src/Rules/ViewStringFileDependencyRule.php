<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use Hypervel\PHPStan\Support\RuntimeInputs;
use Hypervel\PHPStan\Types\ViewStringType;
use PhpParser\Node;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredParameter;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Node\FileNode;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

use function file_get_contents;
use function implode;
use function preg_match;
use function preg_quote;

/**
 * Declares that a file whose own PHPDoc uses a view-string depends on which views exist.
 *
 * A name returned under `@return view-string`, a property default and an inline `@var` are
 * checked in the declaring file, which may declare no class, so the class declarations
 * ViewStringTypeNodeResolverExtension records do not reach it. A global type alias that
 * resolves to a view-string counts as one.
 *
 * @implements Rule<FileNode>
 */
#[AutowiredService]
class ViewStringFileDependencyRule implements Rule
{
    /**
     * The pattern matching view-string and the global type aliases that resolve to one, built on first use.
     */
    private ?string $pattern = null;

    /**
     * Create a new view-string file dependency rule.
     *
     * @param array<string, string> $typeAliases the global type aliases
     */
    public function __construct(
        private RuntimeInputs $runtimeInputs,
        private TypeStringResolver $typeStringResolver,
        #[AutowiredParameter]
        private array $typeAliases,
    ) {
    }

    /**
     * Get the node type the rule checks.
     */
    public function getNodeType(): string
    {
        return FileNode::class;
    }

    /**
     * Declare the dependency of a file whose text names a view-string type.
     *
     * @param FileNode $node
     * @param DependencyTracker&Scope $scope
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (preg_match($this->pattern ??= $this->createPattern(), (string) file_get_contents($scope->getFile())) === 1) {
            $this->runtimeInputs->trackViews($scope);
        }

        return [];
    }

    /**
     * Create the pattern matching view-string and the global type aliases that resolve to one.
     */
    private function createPattern(): string
    {
        $names = ['view-string'];

        foreach ($this->typeAliases as $alias => $definition) {
            if (ViewStringType::occursIn($this->typeStringResolver->resolve($definition))) {
                $names[] = preg_quote($alias, '/');
            }
        }

        return '/\b(?:' . implode('|', $names) . ')\b/';
    }
}
