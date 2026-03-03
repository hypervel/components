<?php

declare(strict_types=1);

namespace Hypervel\Tests\Server\Stubs;

use Hypervel\Context\Context;
use Hypervel\Server\Listeners\InitProcessTitleListener;

class InitProcessTitleListenerStub2 extends InitProcessTitleListener
{
    protected string $dot = '#';

    public function setTitle(string $title): void
    {
        if ($this->isSupportedOS()) {
            Context::set('test.server.process.title', $title);
        }
    }

    public function isSupportedOS(): bool
    {
        return parent::isSupportedOS();
    }
}
