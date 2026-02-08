<?php

declare(strict_types=1);

namespace Hypervel\View\Middleware;

use Hyperf\Contract\MessageBag as MessageBagContract;
use Hyperf\Contract\MessageProvider;
use Hyperf\ViewEngine\Contract\FactoryInterface;
use Hyperf\ViewEngine\ViewErrorBag;
use Hypervel\Contracts\Session\Session as SessionContract;
use Hypervel\Support\MessageBag;
use Hypervel\Validation\ValidationException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

class ValidationExceptionHandle implements MiddlewareInterface
{
    public function __construct(
        protected ContainerInterface $container,
        protected SessionContract $session,
        protected FactoryInterface $view,
        protected ResponseInterface $response
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $response = $handler->handle($request);
        } catch (Throwable $throwable) {
            if ($throwable instanceof ValidationException) {
                /* @var ValidationException $throwable */
                $this->withErrors($throwable->errors(), $throwable->errorBag);

                /* @phpstan-ignore-next-line */
                return $this->response->redirect(
                    $this->session->previousUrl()
                );
            }

            throw $throwable;
        }

        return $response;
    }

    public function withErrors($provider, $key = 'default'): static
    {
        $value = $this->parseErrors($provider);

        $errors = $this->session->get('errors', new ViewErrorBag());

        if (! $errors instanceof ViewErrorBag) {
            $errors = new ViewErrorBag();
        }

        /* @phpstan-ignore-next-line */
        $this->session->flash(
            'errors',
            $errors->put($key, $value)
        );

        return $this;
    }

    protected function parseErrors($provider): MessageBagContract
    {
        if ($provider instanceof MessageProvider) {
            return $provider->getMessageBag();
        }

        return new MessageBag((array) $provider);
    }
}
