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
use Hypervel\Passport\Passport;
use Hypervel\Passport\ServerFactory;
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
        protected ServerFactory $servers,
    ) {
    }

    /**
     * Describe the supported schemes and the supplied credential's error.
     *
     * @param list<string> $scopes
     */
    public function header(Request $request, ?string $error = null, array $scopes = []): string
    {
        if ($error === 'invalid_token' && $this->servers->authenticationError($request)?->getErrorType() === 'invalid_dpop_proof') {
            $error = 'invalid_dpop_proof';
        }

        $scheme = BearerRequestFactory::credentials($request->headers->get('Authorization') ?? '')['scheme'] ?? null;
        $details = $error === null ? '' : ' error="' . $error . '"';

        if ($error === 'insufficient_scope' && $scopes !== []) {
            $details .= ', scope="' . addcslashes(implode(' ', $scopes), '\"') . '"';
        }

        $resourceMetadata = Passport::$resourceMetadataCallback === null ? null : (Passport::$resourceMetadataCallback)($request);
        $metadata = $resourceMetadata === null ? '' : 'resource_metadata="' . addcslashes($resourceMetadata, '\"') . '"';
        $header = 'Bearer' . ($metadata === '' ? '' : ' ' . $metadata)
            . ($scheme === 'Bearer' && $details !== '' ? ($metadata === '' ? '' : ',') . $details : '');

        if ($this->config->boolean('passport.dpop.enabled')) {
            $header .= ', DPoP algs="' . implode(' ', JwkValidator::ALGORITHMS) . '"'
                . ($metadata === '' ? '' : ', ' . $metadata)
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
        $this->exceptions->afterResponse(function (Response $response, Throwable $exception, Request $renderedRequest) use ($request, $guard, $defaultGuard): void {
            if ($renderedRequest !== $request || $response->getStatusCode() !== 401 || ! $exception instanceof AuthenticationException
                || $response->headers->has('WWW-Authenticate')) {
                return;
            }

            foreach ($exception->guards() ?: [null] as $failedGuard) {
                if (($failedGuard ?? $defaultGuard) === $guard) {
                    $response->headers->set('WWW-Authenticate', $this->header($request, 'invalid_token'));

                    return;
                }
            }
        });
    }

    /**
     * Add a challenge to this middleware failure without replacing its rendered body.
     *
     * @param null|list<string> $scopes
     */
    public function forException(Request $request, AuthenticationException|MissingScopeException $exception, ?array $scopes = null): void
    {
        $scopeFailure = $exception instanceof MissingScopeException;
        $status = $scopeFailure ? 403 : 401;
        $this->exceptions->afterResponse(function (Response $response, Throwable $renderedException, Request $renderedRequest) use ($request, $exception, $status, $scopeFailure, $scopes): void {
            // The handler wraps authorization exceptions in an HTTP exception before rendering.
            if ($renderedRequest === $request && $response->getStatusCode() === $status
                && ! $response->headers->has('WWW-Authenticate')
                && ($renderedException === $exception || $renderedException->getPrevious() === $exception)) {
                $response->headers->set('WWW-Authenticate', $this->header(
                    $request,
                    $scopeFailure ? 'insufficient_scope' : 'invalid_token',
                    $scopeFailure ? ($scopes ?? $exception->scopes()) : [],
                ));
            }
        });
    }
}
