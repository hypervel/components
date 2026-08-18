<?php

declare(strict_types=1);

namespace Hypervel\Http\Concerns;

use Override;
use Symfony\Component\HttpFoundation\Request;

/**
 * Optimize preparation of the common Swoole HTTP response.
 */
trait PreparesResponse
{
    /**
     * Prepare the response before it is emitted.
     */
    #[Override]
    public function prepare(Request $request): static
    {
        if ($this->statusCode < 200
            || $this->statusCode === 204
            || $this->statusCode === 304
            || $request->getMethod() === 'HEAD'
            || $request->server->get('SERVER_PROTOCOL') === 'HTTP/1.0'
        ) {
            return parent::prepare($request);
        }

        $contentType = $this->headers->get('Content-Type');

        // A missing content type requires Symfony's request-format lookup.
        if ($contentType === null) {
            return parent::prepare($request);
        }

        // HTTPS preparation mutates cookie defaults and contains a legacy IE
        // download workaround. Fall back only when either can be relevant.
        if ($request->isSecure()
            && ($this->headers->getCookies() !== []
                || stripos($this->headers->get('Content-Disposition') ?? '', 'attachment') !== false)
        ) {
            return parent::prepare($request);
        }

        if (stripos($contentType, 'text/') === 0 && stripos($contentType, 'charset') === false) {
            $this->headers->set('Content-Type', $contentType . '; charset=' . ($this->charset ?: 'utf-8'));
        }

        if ($this->headers->has('Transfer-Encoding')) {
            $this->headers->remove('Content-Length');
        }

        $this->version = '1.1';

        return $this;
    }
}
