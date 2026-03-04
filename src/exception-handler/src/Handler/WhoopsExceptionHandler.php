<?php

declare(strict_types=1);

namespace Hypervel\ExceptionHandler\Handler;

use Hypervel\Context\Context;
use Hypervel\Context\RequestContext;
use Hypervel\ExceptionHandler\ExceptionHandler;
use Hypervel\Session\Store;
use Hypervel\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Whoops\Handler\JsonResponseHandler;
use Whoops\Handler\PlainTextHandler;
use Whoops\Handler\PrettyPageHandler;
use Whoops\Handler\XmlResponseHandler;
use Whoops\Run;
use Whoops\RunInterface;

class WhoopsExceptionHandler extends ExceptionHandler
{
    /**
     * The content type to handler class mapping.
     *
     * @var array<string, class-string>
     */
    protected static array $preference = [
        'text/html' => PrettyPageHandler::class,
        'application/json' => JsonResponseHandler::class,
        'application/xml' => XmlResponseHandler::class,
    ];

    /**
     * Handle the exception using Whoops.
     */
    public function handle(Throwable $throwable, Response $response): Response
    {
        $whoops = new Run();
        [$handler, $contentType] = $this->negotiateHandler();

        $whoops->pushHandler($handler);
        $whoops->allowQuit(false);
        ob_start();
        $whoops->{RunInterface::EXCEPTION_HANDLER}($throwable);
        $content = ob_get_clean();

        return new Response($content, 500, ['Content-Type' => $contentType]);
    }

    /**
     * Determine if this handler should handle the exception.
     */
    public function isValid(Throwable $throwable): bool
    {
        return env('APP_ENV') !== 'prod' && class_exists(Run::class);
    }

    /**
     * Negotiate the appropriate handler based on the request's Accept header.
     */
    protected function negotiateHandler(): array
    {
        $request = RequestContext::get();
        $accepts = $request->headers->get('Accept', '');
        foreach (self::$preference as $contentType => $handler) {
            if (Str::contains($accepts, $contentType)) {
                return [$this->setupHandler(new $handler()), $contentType];
            }
        }
        return [new PlainTextHandler(), 'text/plain'];
    }

    /**
     * Configure the Whoops handler with request and session data.
     */
    protected function setupHandler(mixed $handler): mixed
    {
        if ($handler instanceof PrettyPageHandler) {
            $handler->handleUnconditionally(true);

            if (defined('BASE_PATH')) {
                $handler->setApplicationRootPath(BASE_PATH);
            }

            $request = RequestContext::get();
            $handler->addDataTableCallback('Request Query', fn () => $request->query->all());
            $handler->addDataTableCallback('Request Post', fn () => $request->request->all());
            $handler->addDataTableCallback('Request Server', fn () => $request->server->all());
            $handler->addDataTableCallback('Request Cookies', fn () => $request->cookies->all());
            $handler->addDataTableCallback('Request Files', fn () => $request->files->all());
            $handler->addDataTableCallback('Request Attributes', fn () => $request->attributes->all());

            $session = Context::get(Store::CONTEXT_KEY);
            if ($session) {
                $handler->addDataTableCallback('Hypervel Session', [$session, 'all']);
            }
        } elseif ($handler instanceof JsonResponseHandler) {
            $handler->addTraceToOutput(true);
        }

        return $handler;
    }
}
