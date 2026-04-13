<?php

declare(strict_types=1);

namespace Hypervel\Tests\Server\Fixtures;

use Hypervel\Context\CoroutineContext;
use Hypervel\Server\Listeners\InitProcessTitleListener;

class InitProcessTitleListenerStub extends InitProcessTitleListener
{
    public function setTitle(string $title): void
    {
        if ($this->isSupportedOS()) {
            CoroutineContext::set('test.server.process.title', $title);
        }
    }

    public function isSupportedOS(): bool
    {
        return parent::isSupportedOS();
    }
}
