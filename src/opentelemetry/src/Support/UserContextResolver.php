<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Support;

use Hypervel\Contracts\Auth\Factory;
use Hypervel\OpenTelemetry\OpenTelemetryManager;
use OpenTelemetry\API\Behavior\LogsMessagesTrait;
use OpenTelemetry\SemConv\Incubating\Attributes\UserIncubatingAttributes;
use Swoole\Coroutine\CanceledException;
use Throwable;
use Traversable;
use UnexpectedValueException;

class UserContextResolver
{
    use LogsMessagesTrait;

    /**
     * Create an authenticated-user context resolver.
     */
    public function __construct(
        protected Factory $auth,
        protected OpenTelemetryManager $manager,
    ) {
    }

    /**
     * Resolve user attributes at most once for a request.
     *
     * @return array<string, mixed>
     */
    public function resolve(RequestTelemetryState $state): array
    {
        if ($state->userResolved) {
            return $state->userAttributes;
        }

        $state->userResolved = true;
        $guard = $this->auth->guard();

        if (! $guard->hasUser() || ($user = $guard->user()) === null) {
            return [];
        }

        $resolver = $this->manager->userResolver();

        if ($resolver === null) {
            $identifier = $user->getAuthIdentifier();

            if (is_int($identifier) || is_string($identifier)) {
                $state->userAttributes = [UserIncubatingAttributes::USER_ID => (string) $identifier];
            }

            return $state->userAttributes;
        }

        try {
            $attributes = $resolver($user);

            if ($attributes instanceof Traversable) {
                $attributes = iterator_to_array($attributes);
            }

            if (! is_array($attributes)) {
                throw new UnexpectedValueException('The OpenTelemetry user resolver must return an iterable attribute map.');
            }

            $state->userAttributes = $attributes;
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            self::logError('OpenTelemetry user-context resolution failed.', ['exception' => $exception]);
        }

        return $state->userAttributes;
    }
}
