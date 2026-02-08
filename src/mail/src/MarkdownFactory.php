<?php

declare(strict_types=1);

namespace Hypervel\Mail;

use Hyperf\ViewEngine\Contract\FactoryInterface;
use Hypervel\Config\Repository;

class MarkdownFactory
{
    public function __construct(
        protected FactoryInterface $factory,
        protected Repository $config,
    ) {
    }

    public function __invoke(): Markdown
    {
        return new Markdown(
            $this->factory,
            $this->config->get('mail.markdown', [])
        );
    }
}
