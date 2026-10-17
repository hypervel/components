<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use Closure;
use DateInterval;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Http\Request;
use Hypervel\Passport\Bridge\TokenIssuance;
use Hypervel\Passport\Enums\AuthorizationApproval;
use Hypervel\Passport\Events\AuthorizationApproved;
use League\OAuth2\Server\Entities\DeviceCodeEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;

class AuthorizationFinalizer
{
    /**
     * Create the authorization finalizer.
     */
    public function __construct(
        protected ServerFactory $servers,
        protected ConsentRepository $consents,
        protected ClientRepository $clients,
        protected Dispatcher $events,
        protected ResourceRepository $resources,
    ) {
    }

    /**
     * Attach consent and extension data atomically before completing an authorization.
     *
     * @template TResult
     * @param array<string, mixed> $pending
     * @param Closure(): TResult $complete
     * @return null|TResult
     */
    public function approve(
        Request $request,
        AuthorizationRequestInterface|DeviceCodeEntityInterface $authorization,
        array $pending,
        Authenticatable $user,
        AuthorizationApproval $approval,
        Closure $complete,
    ): mixed {
        $presented = ['scopes' => $pending['scopes'], 'permissions' => $pending['permissions']];
        $selected = $approval === AuthorizationApproval::Prompt
            ? $this->selection($request, $presented)
            : $presented;

        if (isset($presented['permissions']['resources'])) {
            if (empty($selected['permissions']['resources'])) {
                throw OAuthServerException::accessDenied('No resource was approved.');
            }

            $this->resources->validate(array_keys($selected['permissions']['resources']));
        }

        $duration = $pending['remember_consent_for'] === null ? null : DateInterval::__set_state($pending['remember_consent_for']);

        if ($approval === AuthorizationApproval::Prompt && array_key_exists('remember', $request->all())) {
            $remember = $request->input('remember') ?? false;

            if (! is_scalar($remember) || ($remember = filter_var($remember, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)) === null) {
                throw OAuthServerException::invalidRequest('remember');
            }

            if (! $remember) {
                $duration = new DateInterval('PT0S');
            }
        }

        return TokenIssuance::run($this->servers, function () use ($request, $authorization, $pending, $user, $approval, $complete, $presented, $selected, $duration): mixed {
            $issuance = TokenIssuance::current();
            $clientId = $authorization->getClient()->getIdentifier();
            $consent = $this->consents->lockForUser(
                $clientId,
                $pending['provider'],
                $user->getAuthIdentifier(),
                $approval !== AuthorizationApproval::Remembered,
            );

            // Revocation or expiry may have won after the controller's unlocked pre-check.
            if ($approval === AuthorizationApproval::Remembered
                && ($consent === null || ! $consent->covers($selected['scopes'], $selected['permissions']))) {
                return null;
            }

            if ($approval === AuthorizationApproval::Prompt) {
                $this->consents->remember($consent, $presented, $selected, $duration);
            }

            $attributes = $pending['attributes'];

            if ($this->events->hasListeners(AuthorizationApproved::class)) {
                $client = $this->clients->findActive($clientId) ?? throw OAuthServerException::unauthorizedClient();
                $event = new AuthorizationApproved(
                    $request,
                    $pending['parameters'],
                    $user,
                    $pending['provider'],
                    $pending['guard'],
                    $client,
                    $pending['grant_type'],
                    $approval,
                    $selected['scopes'],
                    $selected['permissions'],
                    $attributes,
                );
                $this->events->dispatch($event);
                $attributes = $event->attributes;
            }

            $issuance->setAuthorizationAttributes([
                ...$attributes,
                'provider' => $pending['provider'],
                'consent_id' => $consent->getKey(),
            ]);
            $issuance->approvedScopes = $selected['scopes'];
            $issuance->resources = $selected['permissions']['resources'] ?? null;

            if ($authorization instanceof AuthorizationRequestInterface) {
                $issuance->dpopJkt = $pending['parameters']['dpop_jkt'] ?? null;
                $authorization->setScopes(array_values(array_filter(
                    $authorization->getScopes(),
                    static fn (ScopeEntityInterface $scope): bool => in_array($scope->getIdentifier(), $selected['scopes'], true),
                )));
            }

            return $complete();
        });
    }

    /**
     * Validate submitted choices against the saved consent presentation.
     *
     * @param array{scopes: list<string>, permissions: array<string, array>} $presented
     * @return array{scopes: list<string>, permissions: array<string, array>}
     */
    protected function selection(Request $request, array $presented): array
    {
        $input = $request->all();
        $scopes = array_key_exists('scopes', $input)
            ? $this->selectedValues($input['scopes'], $presented['scopes'], 'scopes')
            : $presented['scopes'];
        $permissions = array_key_exists('permissions', $input) ? $input['permissions'] : $presented['permissions'];

        if ($permissions === null || $permissions === '') {
            $permissions = [];
        }

        if (! is_array($permissions) || array_diff_key($permissions, $presented['permissions']) !== []) {
            throw OAuthServerException::invalidRequest('permissions');
        }

        foreach ($permissions as $namespace => $values) {
            if ($namespace === 'resources') {
                if (! is_array($values) || array_diff_key($values, $presented['permissions']['resources']) !== []) {
                    throw OAuthServerException::invalidRequest('permissions');
                }

                foreach ($values as $resource => $resourceScopes) {
                    $permissions['resources'][$resource] = array_values(array_intersect(
                        $this->selectedValues($resourceScopes, $presented['permissions']['resources'][$resource], 'permissions'),
                        $scopes,
                    ));
                }
            } else {
                $permissions[$namespace] = $this->selectedValues($values, $presented['permissions'][$namespace], 'permissions');
            }
        }

        if (isset($presented['permissions']['resources'])) {
            $resourceScopes = array_merge([], ...array_values($permissions['resources'] ?? []));
            $scopes = array_values(array_intersect($scopes, $resourceScopes));
        }

        return ['scopes' => $scopes, 'permissions' => $permissions];
    }

    /**
     * Validate a selected set, including an explicitly empty form selection.
     *
     * @param list<string> $presented
     * @return list<string>
     */
    protected function selectedValues(mixed $selected, array $presented, string $parameter): array
    {
        if ($selected === null || $selected === '') {
            return [];
        }

        if (! is_array($selected) || ! array_is_list($selected)) {
            throw OAuthServerException::invalidRequest($parameter);
        }

        foreach ($selected as $value) {
            if (! is_string($value) || ! in_array($value, $presented, true)) {
                throw OAuthServerException::invalidRequest($parameter);
            }
        }

        return array_values(array_unique($selected));
    }
}
