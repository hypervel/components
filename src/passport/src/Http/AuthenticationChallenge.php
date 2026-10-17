<?php

declare(strict_types=1);

namespace Hypervel\Passport\Http;

use Hypervel\Auth\AuthenticationException;
use Hypervel\Auth\AuthManager;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Http\Request;
use Hypervel\Passport\Bridge\BearerRequestFactory;
use Hypervel\Passport\Exceptions\MissingScopeException;
use Hypervel\Passport\JwkValidator;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuthenticationChallenge
{
    /**
     * Create the resource authentication challenge formatter.
     */
    public function __construct(
        protected Repository $config,
        protected ExceptionHandler $exceptions,
        protected AuthManager $auth,
    ) {
    }

    /**
     * Describe the supported schemes and the supplied credential's error.
     *
     * @param list<string> $scopes
     */
    public function header(Request $request, ?string $error = null, array $scopes = []): string
    {
        $scheme = BearerRequestFactory::credentials($request->headers->get('Authorization') ?? '')['scheme'] ?? null;
        $details = $error === null ? '' : ' error="' . $error . '"';

        if ($error === 'insufficient_scope' && $scopes !== []) {
            $details .= ', scope="' . addcslashes(implode(' ', $scopes), '\"') . '"';
        }

        $header = 'Bearer' . ($scheme === 'Bearer' ? $details : '');

        if ($this->config->boolean('passport.dpop.enabled')) {
            $header .= ', DPoP algs="' . implode(' ', JwkValidator::ALGORITHMS) . '"'
                . ($scheme === 'DPoP' && $details !== '' ? ',' . $details : '');
        }

        return $header;
    }

    /**
     * Add a challenge if this guard's failure becomes an authentication response.
     */
    public function forGuard(Request $request, string $guard): void
    {
        $defaultGuard = $this->auth->getDefaultDriver();
        $header = $this->header($request, 'invalid_token');

        $this->exceptions->afterResponse(static function (Response $response, Throwable $exception, Request $renderedRequest) use ($request, $guard, $defaultGuard, $header): void {
            if ($renderedRequest !== $request || $response->getStatusCode() !== 401 || ! $exception instanceof AuthenticationException) {
                return;
            }

            foreach ($exception->guards() ?: [null] as $failedGuard) {
                if (($failedGuard ?? $defaultGuard) === $guard) {
                    $response->headers->set('WWW-Authenticate', $header);

                    return;
                }
            }
        });
    }

    /**
     * Add a challenge to this middleware failure without replacing its rendered body.
     */
    public function forException(Request $request, AuthenticationException|MissingScopeException $exception): void
    {
        $scopeFailure = $exception instanceof MissingScopeException;
        $status = $scopeFailure ? 403 : 401;
        $header = $this->header($request, $scopeFailure ? 'insufficient_scope' : 'invalid_token', $scopeFailure ? $exception->scopes() : []);

        $this->exceptions->afterResponse(static function (Response $response, Throwable $renderedException, Request $renderedRequest) use ($request, $exception, $status, $header): void {
            // The handler wraps authorization exceptions in an HTTP exception before rendering.
            if ($renderedRequest === $request && $response->getStatusCode() === $status
                && ($renderedException === $exception || $renderedException->getPrevious() === $exception)) {
                $response->headers->set('WWW-Authenticate', $header);
            }
        });
    }
}
