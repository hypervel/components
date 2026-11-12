<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use Closure;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Type\ObjectType;

use function array_merge;
use function in_array;
use function preg_match;
use function str_starts_with;

/**
 * Report relationship queries and eager loads that name a relation the model does not define.
 *
 * @implements Rule<CallLike>
 */
#[AutowiredService]
class RelationExistenceRule implements Rule
{
    // These parse a column selection, such as `posts:id`, from each relation name.
    private const array LOADING_METHODS = ['with', 'withonly', 'withwherehas', 'load', 'loadmissing'];

    // These pass the relation name to the existence query as written.
    private const array QUERY_METHODS = [
        'has',
        'orhas',
        'doesnthave',
        'ordoesnthave',
        'wherehas',
        'orwherehas',
        'wheredoesnthave',
        'orwheredoesnthave',
        'whererelation',
        'orwhererelation',
        'withwhererelation',
        'wheredoesnthaverelation',
        'orwheredoesnthaverelation',
    ];

    // These also get the relation by its name directly, so a dotted name is not a path.
    private const array MORPH_METHODS = [
        'hasmorph',
        'orhasmorph',
        'doesnthavemorph',
        'ordoesnthavemorph',
        'wherehasmorph',
        'orwherehasmorph',
        'wheredoesnthavemorph',
        'orwheredoesnthavemorph',
        'wheremorphrelation',
        'orwheremorphrelation',
        'wheremorphdoesnthaverelation',
        'orwheremorphdoesnthaverelation',
    ];

    /**
     * Create the rule.
     */
    public function __construct(
        private ModelRuleHelper $modelRuleHelper,
        private RelationExistenceHelper $relationExistenceHelper,
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
     * Report the relations the call names that the model does not define.
     *
     * @param DependencyTracker&Scope $scope
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! ($node instanceof MethodCall || $node instanceof StaticCall) || ! $node->name instanceof Identifier) {
            return [];
        }

        $method = $node->name->toLowerString();
        $aggregate = preg_match('/^(with|load)(aggregate|count|max|min|sum|avg|exists)$/', $method) === 1;

        $morph = in_array($method, self::MORPH_METHODS, true);
        $verbatim = $morph || in_array($method, self::QUERY_METHODS, true);

        if (! $aggregate && ! $verbatim && ! in_array($method, self::LOADING_METHODS, true)) {
            return [];
        }

        $args = $node->getArgs();

        if ($args === [] || $args[0]->unpack) {
            return [];
        }

        // with() keeps a name paired with a callback as written. Unlike upstream, withWhereHas() is not
        // included: Hypervel's parses the column selection with a callback too.
        $selectsColumns = ! $verbatim;

        if ($method === 'with') {
            foreach ($args as $position => $arg) {
                if ($arg->name === null ? $position !== 1 : $arg->name->toString() !== 'callback') {
                    continue;
                }

                $selectsColumns = ! (new ObjectType(Closure::class))->isSuperTypeOf($scope->getType($arg->value))->yes();
            }
        }

        foreach ($args as $arg) {
            if ($arg->name !== null && in_array($arg->name->toString(), ['relation', 'relations'], true)) {
                $args = [$arg];

                break;
            }
        }

        // Unlike upstream, a class-string receiver, as in $modelClass::with(), is checked as its model.
        $type = $this->modelRuleHelper->callerType($node, $scope);
        $collection = (new ObjectType(Collection::class))->isSuperTypeOf($type)->yes();

        if ($collection && str_starts_with($method, 'load')) {
            $type = $type->getTemplateType(Collection::class, 'TModel');
        } elseif ($collection) {
            return [];
        }

        $variadic = in_array($method, ['with', 'load', 'loadmissing', 'withcount'], true)
            || ($method === 'loadcount' && ! $collection && (new ObjectType(Model::class))->isSuperTypeOf($type)->yes());
        $args = $variadic && $scope->getType($args[0]->value)->isString()->yes() ? $args : [$args[0]];
        $errors = [];

        foreach ($args as $arg) {
            if ($arg->unpack) {
                continue;
            }

            $errors = array_merge($errors, $this->relationExistenceHelper->check($scope->getType($arg->value), $type, $node, $scope, $aggregate, $selectsColumns, ! $morph));
        }

        return $errors;
    }
}
