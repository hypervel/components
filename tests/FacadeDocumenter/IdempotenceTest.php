<?php

declare(strict_types=1);

namespace Hypervel\Tests\FacadeDocumenter;

class IdempotenceTest extends FacadeDocumenterTestCase
{
    public function testSecondRunProducesByteIdenticalOutput()
    {
        $this->writeAppFile(
            'Idempotence/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Idempotence;

                class Proxy
                {
                    public function alpha(string $input): string
                    {
                        return $input;
                    }

                    public function beta(int $count = 1): int
                    {
                        return $count;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'Idempotence/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Idempotence;

                /**
                 * @see \App\Idempotence\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $first = $this->runDocumenter(['App\Idempotence\Facade']);
        $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput() . $first->getOutput());

        $afterFirstRun = $this->appFileContents('App\Idempotence\Facade');

        $second = $this->runDocumenter(['App\Idempotence\Facade']);
        $this->assertSame(0, $second->getExitCode(), $second->getErrorOutput() . $second->getOutput());

        $afterSecondRun = $this->appFileContents('App\Idempotence\Facade');

        $this->assertSame($afterFirstRun, $afterSecondRun, 'Second run mutated the file despite no underlying changes');
    }

    public function testLintAfterGenerateExitsZero()
    {
        $this->writeAppFile(
            'Idempotence/Lint/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Idempotence\Lint;

                class Proxy
                {
                    public function noop(): void
                    {
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'Idempotence/Lint/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Idempotence\Lint;

                /**
                 * @see \App\Idempotence\Lint\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $generate = $this->runDocumenter(['App\Idempotence\Lint\Facade']);
        $this->assertSame(0, $generate->getExitCode(), $generate->getErrorOutput() . $generate->getOutput());

        $lint = $this->runDocumenter(['--lint', 'App\Idempotence\Lint\Facade']);
        $this->assertSame(0, $lint->getExitCode(), '--lint should exit 0 when docblock is already up to date');
    }
}
