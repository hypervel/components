<?php

declare(strict_types=1);

namespace Hypervel\Mail;

use Hypervel\View\Contracts\Factory as FactoryContract;
use Hypervel\Config\Repository;

class MarkdownFactory
{
    public function __construct(
        protected FactoryContract $factory,
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
