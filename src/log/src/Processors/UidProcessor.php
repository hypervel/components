<?php

declare(strict_types=1);

namespace Hypervel\Log\Processors;

use Hypervel\Context\CoroutineContext;
use Monolog\LogRecord;
use Monolog\Processor\UidProcessor as MonologUidProcessor;
use Override;

class UidProcessor extends MonologUidProcessor
{
    /**
     * The next worker-unique processor identifier.
     *
     * This identity must never reset while old coroutine-context entries may
     * survive, otherwise a new processor can reuse another processor's UID.
     */
    private static int $nextProcessorId = 0;

    private readonly string $contextKey;

    /**
     * @param int<1, 32> $length
     */
    public function __construct(
        private readonly int $length = 7
    ) {
        parent::__construct($length);

        $this->contextKey = '__log.uid_processor.' . ++self::$nextProcessorId;
    }

    #[Override]
    public function __invoke(LogRecord $record): LogRecord
    {
        $record->extra['uid'] = $this->getUid();

        return $record;
    }

    #[Override]
    public function getUid(): string
    {
        return CoroutineContext::getOrSet(
            $this->contextKey,
            fn (): string => $this->generateUid()
        );
    }

    #[Override]
    public function reset(): void
    {
        CoroutineContext::forget($this->contextKey);
    }

    private function generateUid(): string
    {
        return substr(bin2hex(random_bytes((int) ceil($this->length / 2))), 0, $this->length);
    }
}
