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

use function array_any;
use function in_array;
use function is_string;
use function sprintf;
use function str_starts_with;
use function strtolower;

use const INPUT_COOKIE;
use const INPUT_GET;
use const INPUT_POST;

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

    private const string DISCONNECT_TIP = 'Stream through a generator callback passed to response()->stream() or an IterableStreamedResponse, which stops at the first failed write after the client disconnects. Call cancelOnDisconnect() to also stop it during a wait when the connection closes.';

    private const string INPUT_TIP = 'Read input from the request with its query(), input(), post(), cookie() and file() methods.';

    private const string HEADER_TIP = "Read the request's headers with its header() method.";

    private const string FILTER_TIP = 'Read the value from the request with its query(), input(), post() or cookie() methods, then validate or filter that value.';

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
        'getallheaders' => self::HEADER_TIP,
        'apache_request_headers' => self::HEADER_TIP,
        'filter_input' => self::FILTER_TIP,
        'filter_input_array' => self::FILTER_TIP,
        'filter_has_var' => self::FILTER_TIP,
        'connection_aborted' => self::DISCONNECT_TIP,
        'connection_status' => self::DISCONNECT_TIP,
        'ignore_user_abort' => self::DISCONNECT_TIP,
    ];

    /**
     * The input type parameter of each filter function that reads SAPI input, by lowercase name.
     */
    private const array FILTER_FUNCTIONS = [
        'filter_input' => 'type',
        'filter_input_array' => 'type',
        'filter_has_var' => 'input_type',
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

        $name = strtolower($function->getName());

        // The filter functions read the original SAPI input for request input types. INPUT_SERVER and INPUT_ENV
        // read the process, like $_SERVER and $_ENV, which aren't request state.
        if (isset(self::FILTER_FUNCTIONS[$name])) {
            $type = $node->getArg(self::FILTER_FUNCTIONS[$name], 0);

            if ($type === null || ! array_any(
                $scope->getType($type->value)->getConstantScalarValues(),
                static fn (mixed $value): bool => in_array($value, [INPUT_GET, INPUT_POST, INPUT_COOKIE], true),
            )) {
                return [];
            }
        }

        return [$this->buildError(
            sprintf("%s() does not act on Hypervel's request, response or session.", $function->getName()),
            self::FUNCTIONS[$name] ?? self::SESSION_TIP,
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
