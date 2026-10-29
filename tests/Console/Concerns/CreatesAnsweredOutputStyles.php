<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Concerns;

use Hypervel\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

trait CreatesAnsweredOutputStyles
{
    /**
     * Build a real OutputStyle whose interactive input is pre-fed the given typed answer.
     */
    protected function outputStyleWithAnswer(string $answer): OutputStyle
    {
        $input = new ArrayInput([]);

        $stream = fopen('php://memory', 'w+');
        fwrite($stream, $answer . "\n");
        rewind($stream);
        $input->setStream($stream);

        return new OutputStyle($input, new BufferedOutput);
    }
}
