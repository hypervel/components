<?php

declare(strict_types=1);

namespace Hypervel\ExceptionHandler\Handler;

use Hyperf\Contract\SessionInterface;
use Hypervel\HttpMessage\Stream\SwooleStream;
use Hypervel\Context\Context;
use Hypervel\Context\RequestContext;
use Hypervel\ExceptionHandler\ExceptionHandler;
use Hypervel\Support\Str;
use Hypervel\Contracts\Http\ResponsePlusInterface;
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
    public function handle(Throwable $throwable, ResponsePlusInterface $response)
    {
        $whoops = new Run();
        [$handler, $contentType] = $this->negotiateHandler();

        $whoops->pushHandler($handler);
        $whoops->allowQuit(false);
        ob_start();
        $whoops->{RunInterface::EXCEPTION_HANDLER}($throwable);
        $content = ob_get_clean();
        return $response
            ->setStatus(500)
            ->addHeader('Content-Type', $contentType)
            ->setBody(new SwooleStream($content));
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
        $accepts = $request->getHeaderLine('accept');
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
            $handler->addDataTableCallback('PSR7 Query', [$request, 'getQueryParams']);
            $handler->addDataTableCallback('PSR7 Post', [$request, 'getParsedBody']);
            $handler->addDataTableCallback('PSR7 Server', [$request, 'getServerParams']);
            $handler->addDataTableCallback('PSR7 Cookie', [$request, 'getCookieParams']);
            $handler->addDataTableCallback('PSR7 File', [$request, 'getUploadedFiles']);
            $handler->addDataTableCallback('PSR7 Attribute', [$request, 'getAttributes']);

            $session = Context::get(SessionInterface::class);
            if ($session) {
                $handler->addDataTableCallback('Hypervel Session', [$session, 'all']);
            }
        } elseif ($handler instanceof JsonResponseHandler) {
            $handler->addTraceToOutput(true);
        }

        return $handler;
    }
}
