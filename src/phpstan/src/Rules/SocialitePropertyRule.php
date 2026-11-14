<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use Hypervel\Socialite\AbstractProvider;
use Hypervel\Socialite\Two\AbstractProvider as OAuth2Provider;
use Hypervel\Socialite\Two\FacebookProvider;
use Hypervel\Socialite\Two\SlackProvider;
use PhpParser\Node;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function sprintf;
use function strtolower;

/**
 * Reports a Socialite provider subclass using a property that holds only the provider's default.
 *
 * Providers are cached for the worker, so each request's value lives in the provider's coroutine
 * context, which the framework's getter reads before falling back to the property.
 *
 * @implements Rule<PropertyFetch>
 *
 * @internal
 */
#[Conditional(attribute: new AutowiredService, if: '%checkSocialiteProviders%')]
class SocialitePropertyRule implements Rule
{
    /**
     * The context-backed properties, each with the framework class whose getter reads it and the methods that read and set the request's value.
     */
    private const array PROPERTIES = [
        'parameters' => [AbstractProvider::class, 'getParameters', 'with'],
        'stateless' => [AbstractProvider::class, 'isStateless', 'stateless'],
        'additionalConfig' => [AbstractProvider::class, 'getConfig', 'setConfig'],
        'clientId' => [OAuth2Provider::class, 'getClientId', 'setConfig'],
        'clientSecret' => [OAuth2Provider::class, 'getClientSecret', 'setConfig'],
        'redirectUrl' => [OAuth2Provider::class, 'getRedirectUrl', 'redirectUrl'],
        'scopes' => [OAuth2Provider::class, 'getScopes', 'setScopes'],
        'usesPKCE' => [OAuth2Provider::class, 'usesPKCE', 'enablePKCE'],
        'fields' => [FacebookProvider::class, 'getFields', 'fields'],
        'popup' => [FacebookProvider::class, 'getPopup', 'asPopup'],
        'reRequest' => [FacebookProvider::class, 'getReRequest', 'reRequest'],
        'version' => [FacebookProvider::class, 'getGraphVersion', 'usingGraphVersion'],
        'scopeKey' => [SlackProvider::class, 'getScopeKey', 'setScopeKey'],
    ];

    /**
     * Get the node type the rule checks.
     */
    public function getNodeType(): string
    {
        return PropertyFetch::class;
    }

    /**
     * Report a subclass reading or writing a context-backed property directly.
     *
     * @param PropertyFetch $node
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->var instanceof Variable
            || $node->var->name !== 'this'
            || ! $node->name instanceof Identifier
            || ! isset(self::PROPERTIES[$node->name->toString()])
            || ! $scope->isInClass()
        ) {
            return [];
        }

        $property = $node->name->toString();
        [$framework, $getter, $setter] = self::PROPERTIES[$property];
        $class = $scope->getClassReflection();
        $function = strtolower($scope->getFunction()?->getName() ?? '');

        // The framework class owns the property, and a getter override reads the default by design.
        // A constructor runs once when the provider is built, so it can only set or read the default.
        if ($class->getName() === $framework
            || ! $class->is($framework)
            || $function === '__construct'
            || $function === strtolower($getter)
        ) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf('$this->%s holds the provider\'s default for every request, not the current request\'s value.', $property))
                ->identifier('hypervel.socialiteProperty')
                ->tip(sprintf('Read it with %s() and set it with %s(), which use the provider\'s coroutine context.', $getter, $setter))
                ->build(),
        ];
    }
}
