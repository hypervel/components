<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Command\Traits;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

trait Foo
{
    private ?string $propertyFoo = null;

    protected function setUpFoo(?InputInterface $input, ?OutputInterface $output): void
    {
        $this->propertyFoo = 'foo';
    }
}
