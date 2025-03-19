<?php

declare(strict_types=1);

namespace Hypervel\Telescope;

use Hypervel\Foundation\Exceptions\Contracts\ExceptionHandler as ExceptionHandlerContract;
use Throwable;

class IncomingExceptionEntry extends IncomingEntry
{
    /**
     * Create a new incoming entry instance.
     *
     * @param Throwable $exception the underlying exception instance
     */
    public function __construct(
        public Throwable $exception,
        array $content
    ) {
        parent::__construct($content);
    }

    /**
     * Determine if the incoming entry is a reportable exception.
     */
    public function isReportableException(): bool
    {
        $handler = app(ExceptionHandlerContract::class);

        return method_exists($handler, 'shouldReport')
            ? $handler->shouldReport($this->exception) : true;
    }

    /**
     * Determine if the incoming entry is an exception.
     */
    public function isException(): bool
    {
        return true;
    }

    /**
     * Calculate the family look-up hash for the incoming entry.
     */
    public function familyHash(): string
    {
        return md5($this->content['file'] . $this->content['line']);
    }
}
