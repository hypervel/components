<?php

declare(strict_types=1);

namespace Hypervel\Tests\FacadeDocumenter;

class LintExitCodeTest extends FacadeDocumenterTestCase
{
    public function testLintOnUpToDateDocblockExitsZero(): void
    {
        $this->writeAppFile(
            'Lint/Clean/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Lint\Clean;

                class Proxy
                {
                    public function ping(): string
                    {
                        return 'pong';
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'Lint/Clean/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Lint\Clean;

                /**
                 * @see \App\Lint\Clean\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        // Generate so the facade is current.
        $generate = $this->runDocumenter(['App\Lint\Clean\Facade']);
        $this->assertSame(0, $generate->getExitCode(), $generate->getErrorOutput() . $generate->getOutput());

        // Lint should agree — no drift.
        $lint = $this->runDocumenter(['--lint', 'App\Lint\Clean\Facade']);
        $this->assertSame(0, $lint->getExitCode(), '--lint should exit 0 when the docblock is already up to date');
    }

    public function testLintOnDriftedDocblockExitsNonZeroAndShowsExpected(): void
    {
        $this->writeAppFile(
            'Lint/Drift/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Lint\Drift;

                class Proxy
                {
                    public function alpha(): string
                    {
                        return 'a';
                    }

                    public function beta(): int
                    {
                        return 1;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'Lint/Drift/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Lint\Drift;

                /**
                 * @see \App\Lint\Drift\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        // Generate first, then manually drop one @method line to simulate drift.
        $generate = $this->runDocumenter(['App\Lint\Drift\Facade']);
        $this->assertSame(0, $generate->getExitCode(), $generate->getErrorOutput() . $generate->getOutput());

        $path = BASE_PATH . '/app/Lint/Drift/Facade.php';
        $driftedContents = preg_replace('/^ \* @method static int beta\(\)\n/m', '', file_get_contents($path));
        $this->assertNotSame(file_get_contents($path), $driftedContents, 'Drift simulation failed to modify the fixture');
        file_put_contents($path, $driftedContents);

        // Lint should now exit non-zero and surface the expected docblock.
        $lint = $this->runDocumenter(['--lint', 'App\Lint\Drift\Facade']);
        $this->assertSame(1, $lint->getExitCode(), '--lint should exit 1 on drift');

        $stdout = $lint->getOutput();
        $this->assertStringContainsString('Did not find expected docblock', $stdout);
        $this->assertStringContainsString('@method static int beta()', $stdout);
    }

    /**
     * Report every drifted facade before exiting.
     */
    public function testLintReportsEveryDriftedFacade(): void
    {
        $this->writeAppFile(
            'Lint/Multiple/FirstProxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Lint\Multiple;

                class FirstProxy
                {
                    public function alpha(): string
                    {
                        return 'a';
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'Lint/Multiple/FirstFacade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Lint\Multiple;

                /**
                 * @see \App\Lint\Multiple\FirstProxy
                 */
                class FirstFacade
                {
                }
                PHP
        );

        $this->writeAppFile(
            'Lint/Multiple/SecondProxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Lint\Multiple;

                class SecondProxy
                {
                    public function beta(): int
                    {
                        return 1;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'Lint/Multiple/SecondFacade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Lint\Multiple;

                /**
                 * @see \App\Lint\Multiple\SecondProxy
                 */
                class SecondFacade
                {
                }
                PHP
        );

        $lint = $this->runDocumenter([
            '--lint',
            'App\Lint\Multiple\FirstFacade',
            'App\Lint\Multiple\SecondFacade',
        ]);

        $this->assertSame(1, $lint->getExitCode());

        $output = $lint->getOutput();

        $this->assertStringContainsString('Did not find expected docblock for [App\Lint\Multiple\FirstFacade].', $output);
        $this->assertStringContainsString('@method static string alpha()', $output);
        $this->assertStringContainsString('Did not find expected docblock for [App\Lint\Multiple\SecondFacade].', $output);
        $this->assertStringContainsString('@method static int beta()', $output);
    }
}
