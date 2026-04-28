<?php

declare(strict_types=1);

namespace Hypervel\Tests\FacadeDocumenter;

class ConditionalDedupeTest extends FacadeDocumenterTestCase
{
    public function testDuplicateNullAcrossBranchesIsDeduped()
    {
        $this->writeAppFile(
            'Conditional/DuplicateNull/Target.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Conditional\DuplicateNull;

                class Target
                {
                }
                PHP
        );

        $this->writeAppFile(
            'Conditional/DuplicateNull/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Conditional\DuplicateNull;

                class Proxy
                {
                    /**
                     * @return ($param is null ? null|\App\Conditional\DuplicateNull\Target : null|object|string)
                     */
                    public function route(?string $param = null): mixed
                    {
                        return null;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'Conditional/DuplicateNull/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Conditional\DuplicateNull;

                /**
                 * @see \App\Conditional\DuplicateNull\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\Conditional\DuplicateNull\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\Conditional\DuplicateNull\Facade');

        // Extract the return-type piece of the @method route(...) line.
        $this->assertMatchesRegularExpression(
            '/@method static ([^ ]+) route\(/',
            $contents
        );

        preg_match('/@method static ([^ ]+) route\(/', $contents, $matches);
        $returnType = $matches[1] ?? '';

        // Split into union members; each should be unique.
        $members = explode('|', $returnType);
        $this->assertSame(count($members), count(array_unique($members)), "Return type has duplicate union members: {$returnType}");

        // Must still include null, the target class, and the alternate-branch members.
        $this->assertContains('null', $members);
        $this->assertContains('\App\Conditional\DuplicateNull\Target', $members);
        $this->assertContains('string', $members);
        $this->assertContains('object', $members);
    }

    public function testNestedGenericUnionIsNotShreddedByDedupe()
    {
        $this->writeAppFile(
            'Conditional/NestedGeneric/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Conditional\NestedGeneric;

                class Proxy
                {
                    /**
                     * @return ($flag is true ? array<int, int|string>|null : string)
                     */
                    public function resolve(bool $flag): mixed
                    {
                        return null;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'Conditional/NestedGeneric/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Conditional\NestedGeneric;

                /**
                 * @see \App\Conditional\NestedGeneric\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\Conditional\NestedGeneric\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\Conditional\NestedGeneric\Facade');

        // The nested union inside <> must survive intact — a naive top-level
        // explode('|') would have split "array<int, int|string>" at the inner
        // pipe and produced garbled pieces like "array<int, int" and "string>".
        $this->assertStringContainsString('array<int, int|string>', $contents);
    }
}
