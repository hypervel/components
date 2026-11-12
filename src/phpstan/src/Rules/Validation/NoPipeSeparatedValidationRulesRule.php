<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules\Validation;

use Hypervel\Ai\Tools\Request as ToolRequest;
use Hypervel\Contracts\Validation\Factory as FactoryContract;
use Hypervel\Contracts\Validation\Validator as ValidatorContract;
use Hypervel\Data\Attributes\Validation\Rule as DataRule;
use Hypervel\Foundation\Validation\ValidatesRequests;
use Hypervel\Http\Request;
use Hypervel\Mcp\Request as McpRequest;
use Hypervel\Prompts\FormBuilder;
use Hypervel\Prompts\Prompt;
use Hypervel\Support\Facades\Validator as ValidatorFacade;
use Hypervel\Validation\ConditionalRules;
use Hypervel\Validation\Factory;
use Hypervel\Validation\Rule as ValidationRule;
use Hypervel\Validation\Rules\AnyOf;
use Hypervel\Validation\Validator;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

use function array_fill_keys;
use function array_keys;
use function array_push;
use function str_starts_with;
use function strtolower;

/**
 * Recommend arrays over pipe-separated rule strings passed to the validator, its wrappers and prompts.
 *
 * @phpstan-import-type Shape from ValidationRuleHelper
 *
 * @implements Rule<CallLike>
 */
#[Conditional(attribute: new AutowiredService, if: '%noPipeSeparatedValidationRules%')]
class NoPipeSeparatedValidationRulesRule implements Rule
{
    /**
     * The parameters taking validation rules, by declaring class and lowercase method name, with the shape of their values.
     */
    private const array METHODS = [
        FactoryContract::class => ['make' => ['rules' => ValidationRuleHelper::MAP]],
        Factory::class => ['validate' => ['rules' => ValidationRuleHelper::MAP]],
        ValidatorFacade::class => [
            'make' => ['rules' => ValidationRuleHelper::MAP],
            'validate' => ['rules' => ValidationRuleHelper::MAP],
        ],
        ValidatorContract::class => [
            'setrules' => ['rules' => ValidationRuleHelper::MAP],
            'sometimes' => ['rules' => ValidationRuleHelper::SET],
        ],
        Validator::class => ['__construct' => ['rules' => ValidationRuleHelper::MAP]],
        Request::class => [
            'validate' => ['rules' => ValidationRuleHelper::MAP],
            'validatewithbag' => ['rules' => ValidationRuleHelper::MAP],
        ],
        McpRequest::class => ['validate' => ['rules' => ValidationRuleHelper::MAP]],
        ToolRequest::class => ['validate' => ['rules' => ValidationRuleHelper::MAP]],
        ValidationRule::class => [
            'when' => ['rules' => ValidationRuleHelper::SET, 'defaultRules' => ValidationRuleHelper::SET],
            'unless' => ['rules' => ValidationRuleHelper::SET, 'defaultRules' => ValidationRuleHelper::SET],
            'anyof' => ['rules' => ValidationRuleHelper::ANY_OF],
        ],
        ConditionalRules::class => [
            '__construct' => ['rules' => ValidationRuleHelper::SET, 'defaultRules' => ValidationRuleHelper::SET],
        ],
        AnyOf::class => ['__construct' => ['rules' => ValidationRuleHelper::ANY_OF]],
        DataRule::class => ['__construct' => ['rules' => ValidationRuleHelper::DATA_SET]],
    ];

    /**
     * The parameters of ValidatesRequests' methods that take validation rules.
     */
    private const array CONTROLLER_METHODS = [
        'validate' => ['rules' => ValidationRuleHelper::MAP],
        'validatewithbag' => ['rules' => ValidationRuleHelper::MAP],
        'validatewith' => ['validator' => ValidationRuleHelper::MAP],
    ];

    /**
     * The lowercase names of the methods that take validation rules.
     *
     * @var null|array<string, true>
     */
    private ?array $methodNames = null;

    /**
     * Create a new rule instance.
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        private ValidationRuleHelper $helper,
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
     * Report the pipe-separated rule strings the call passes.
     *
     * @param CallLike $node
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $entry = match (true) {
            $node instanceof FuncCall => $this->matchFunction($node, $scope),
            $node instanceof New_ => $this->matchConstructor($node, $scope),
            // PHPStan also analyzes a nullsafe call as a method call.
            $node instanceof MethodCall, $node instanceof StaticCall => $this->matchMethod($node, $scope),
            default => null,
        };

        if ($entry === null) {
            return [];
        }

        $errors = [];

        foreach ($entry['parameters'] as $parameter => $shape) {
            foreach ($this->helper->getArgumentValues($node->getArgs(), $entry['variants'], $parameter, $scope) as $value) {
                array_push($errors, ...$this->helper->check($value, $shape, $scope));
            }
        }

        return $errors;
    }

    /**
     * Match validator() and the prompt functions.
     *
     * @return null|array{variants: list<ParametersAcceptor>, parameters: array<string, Shape>}
     */
    private function matchFunction(FuncCall $call, Scope $scope): ?array
    {
        if (! $call->name instanceof Name) {
            return null;
        }

        $name = strtolower($this->reflectionProvider->resolveFunctionName($call->name, $scope) ?? '');

        $parameters = match (true) {
            $name === 'validator' => ['rules' => ValidationRuleHelper::MAP],
            str_starts_with($name, 'hypervel\prompts\\') => ['validate' => ValidationRuleHelper::PROMPT],
            default => null,
        };

        return $parameters === null
            ? null
            : ['variants' => $this->reflectionProvider->getFunction($call->name, $scope)->getVariants(), 'parameters' => $parameters];
    }

    /**
     * Match a constructor taking validation rules.
     *
     * @return null|array{variants: list<ParametersAcceptor>, parameters: array<string, Shape>}
     */
    private function matchConstructor(New_ $new, Scope $scope): ?array
    {
        if (! $new->class instanceof Name) {
            return null;
        }

        $class = $scope->resolveName($new->class);

        if (! $this->reflectionProvider->hasClass($class) || ! $this->reflectionProvider->getClass($class)->hasConstructor()) {
            return null;
        }

        $constructor = $this->reflectionProvider->getClass($class)->getConstructor();
        $parameters = $this->getParameters($constructor->getDeclaringClass(), '__construct');

        return $parameters === [] ? null : ['variants' => $constructor->getVariants(), 'parameters' => $parameters];
    }

    /**
     * Match a method taking validation rules, filtering by name before reflecting the method.
     *
     * @return null|array{variants: list<ParametersAcceptor>, parameters: array<string, Shape>}
     */
    private function matchMethod(MethodCall|StaticCall $call, Scope $scope): ?array
    {
        if (! $call->name instanceof Identifier) {
            return null;
        }

        $name = $call->name->toLowerString();

        if (! isset($this->getMethodNames()[$name])) {
            return null;
        }

        $type = match (true) {
            $call instanceof StaticCall => $call->class instanceof Name ? $scope->resolveTypeByName($call->class) : $scope->getType($call->class),
            default => $scope->getType($call->var),
        };

        $method = $scope->getMethodReflection($type, $call->name->name);
        $parameters = $method === null ? [] : $this->getParameters($method->getDeclaringClass(), $name);

        return $method === null || $parameters === [] ? null : ['variants' => $method->getVariants(), 'parameters' => $parameters];
    }

    /**
     * Get the lowercase names of the methods that take validation rules, so other calls skip reflection.
     *
     * @return array<string, true>
     */
    private function getMethodNames(): array
    {
        if ($this->methodNames !== null) {
            return $this->methodNames;
        }

        $names = array_fill_keys(array_keys(self::CONTROLLER_METHODS), true);

        foreach (self::METHODS as $methods) {
            $names += array_fill_keys(array_keys($methods), true);
        }

        if ($this->reflectionProvider->hasClass(FormBuilder::class)) {
            foreach ($this->reflectionProvider->getClass(FormBuilder::class)->getNativeReflection()->getMethods() as $method) {
                foreach ($method->getParameters() as $parameter) {
                    if ($parameter->getName() === 'validate') {
                        $names[strtolower($method->getName())] = true;
                    }
                }
            }
        }

        return $this->methodNames = $names;
    }

    /**
     * Get the parameters of the class's method that take validation rules, with the shape of their values.
     *
     * @return array<string, Shape>
     */
    private function getParameters(ClassReflection $class, string $method): array
    {
        foreach (self::METHODS as $entry => $methods) {
            if (isset($methods[$method]) && $class->is($entry)) {
                return $methods[$method];
            }
        }

        // Each prompt declares its own constructor, and the form builder's prompt methods take the same argument.
        if ($class->is(FormBuilder::class) || ($method === '__construct' && $class->is(Prompt::class))) {
            return ['validate' => ValidationRuleHelper::PROMPT];
        }

        return isset(self::CONTROLLER_METHODS[$method]) && $this->isDeclaredByValidatesRequests($class, $method)
            ? self::CONTROLLER_METHODS[$method]
            : [];
    }

    /**
     * Determine whether the class's method is ValidatesRequests' own, not an override.
     */
    private function isDeclaredByValidatesRequests(ClassReflection $class, string $method): bool
    {
        $native = $class->getNativeReflection();

        return $this->reflectionProvider->hasClass(ValidatesRequests::class)
            && $native->hasMethod($method)
            && $native->getMethod($method)->getFileName() === $this->reflectionProvider->getClass(ValidatesRequests::class)->getFileName();
    }
}
