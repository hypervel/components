<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function is_string;
use function sprintf;
use function str_starts_with;
use function strtolower;

/**
 * Reports PHP's native request, response and session APIs, which act on PHP's own SAPI state
 * rather than the request and response a Swoole worker is handling.
 *
 * @implements Rule<Expr>
 *
 * @internal
 */
#[Conditional(attribute: new AutowiredService, if: '%noNativeRequestApis%')]
class NoNativeRequestApisRule implements Rule
{
    private const string RESPONSE_TIP = "Set headers, cookies and the status on the response with its header(), cookie() and setStatusCode() methods, and read them from the response's headers.";

    private const string SESSION_TIP = 'Use the session store through $request->session(), session() or the Session facade.';

    private const string DISCONNECT_TIP = 'Stream through a generator callback passed to response()->stream(), or an IterableStreamedResponse, which stop when the client disconnects.';

    private const string INPUT_TIP = 'Read input from the request with its query(), input(), post(), cookie() and file() methods.';

    /**
     * The tip for each reported function, by lowercase name. Other session_* functions use the session tip.
     */
    private const array FUNCTIONS = [
        'header' => self::RESPONSE_TIP,
        'header_remove' => self::RESPONSE_TIP,
        'headers_list' => self::RESPONSE_TIP,
        'headers_sent' => self::RESPONSE_TIP,
        'http_response_code' => self::RESPONSE_TIP,
        'setcookie' => self::RESPONSE_TIP,
        'setrawcookie' => self::RESPONSE_TIP,
        'connection_aborted' => self::DISCONNECT_TIP,
        'connection_status' => self::DISCONNECT_TIP,
        'ignore_user_abort' => self::DISCONNECT_TIP,
    ];

    /**
     * The tip for each reported superglobal.
     */
    private const array SUPERGLOBALS = [
        '_GET' => self::INPUT_TIP,
        '_POST' => self::INPUT_TIP,
        '_COOKIE' => self::INPUT_TIP,
        '_FILES' => self::INPUT_TIP,
        '_REQUEST' => self::INPUT_TIP,
        '_SESSION' => self::SESSION_TIP . ' $_SESSION is shared by every request the worker process handles.',
    ];

    /**
     * Create a new native request APIs rule instance.
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
    ) {
    }

    /**
     * Get the node type the rule checks.
     */
    public function getNodeType(): string
    {
        return Expr::class;
    }

    /**
     * Report a native request API call or superglobal access.
     *
     * @param Expr $node
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($node instanceof Variable) {
            if (! is_string($node->name) || ! isset(self::SUPERGLOBALS[$node->name])) {
                return [];
            }

            $message = $node->name === '_SESSION'
                ? "\$_SESSION is not Hypervel's session."
                : sprintf('$%s does not hold the request Hypervel is handling.', $node->name);

            return [$this->buildError($message, self::SUPERGLOBALS[$node->name])];
        }

        if (! $node instanceof FuncCall || ! $node->name instanceof Name) {
            return [];
        }

        // A cheap name check comes first: every function call reaches this rule.
        $shortName = strtolower($node->name->getLast());

        if (! isset(self::FUNCTIONS[$shortName]) && (! str_starts_with($shortName, 'session_') || $shortName === 'session_create_id')) {
            return [];
        }

        if (! $this->reflectionProvider->hasFunction($node->name, $scope)) {
            return [];
        }

        $function = $this->reflectionProvider->getFunction($node->name, $scope);

        // A function of the application's own namespace with the same name is not PHP's.
        if (! $function->isBuiltin()) {
            return [];
        }

        return [$this->buildError(
            sprintf("%s() does not act on Hypervel's request, response or session.", $function->getName()),
            self::FUNCTIONS[strtolower($function->getName())] ?? self::SESSION_TIP,
        )];
    }

    /**
     * Build the error with its replacement tip.
     */
    private function buildError(string $message, string $tip): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)
            ->identifier('hypervel.nativeRequestApi')
            ->tip($tip)
            ->build();
    }
}
