<?php

declare(strict_types=1);

namespace Hypervel\Tests\FacadeDocumenter;

class ImportResolutionTest extends FacadeDocumenterTestCase
{
    public function testImportedClassShortNameInSeeResolves()
    {
        $this->writeAppFile(
            'Imports/Imported/Real/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Imports\Imported\Real;

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
            'Imports/Imported/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Imports\Imported;

                use App\Imports\Imported\Real\Proxy;

                /**
                 * @see Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\Imports\Imported\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\Imports\Imported\Facade');

        $this->assertStringContainsString('@method static string ping()', $contents);
    }

    public function testAliasedImportInSeeResolves()
    {
        $this->writeAppFile(
            'Imports/Aliased/Real/RealProxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Imports\Aliased\Real;

                class RealProxy
                {
                    public function announce(): string
                    {
                        return 'hi';
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'Imports/Aliased/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Imports\Aliased;

                use App\Imports\Aliased\Real\RealProxy as AliasedProxy;

                /**
                 * @see AliasedProxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\Imports\Aliased\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\Imports\Aliased\Facade');

        $this->assertStringContainsString('@method static string announce()', $contents);
    }

    public function testSameNamespaceUnqualifiedSeeResolves()
    {
        $this->writeAppFile(
            'Imports/SameNamespace/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Imports\SameNamespace;

                class Proxy
                {
                    public function greet(): string
                    {
                        return 'hello';
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'Imports/SameNamespace/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Imports\SameNamespace;

                /**
                 * @see Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\Imports\SameNamespace\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\Imports\SameNamespace\Facade');

        $this->assertStringContainsString('@method static string greet()', $contents);
    }

    public function testImportedInterfaceInSeeResolves()
    {
        $this->writeAppFile(
            'Imports/InterfaceImport/Real/Contract.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Imports\InterfaceImport\Real;

                interface Contract
                {
                    public function describe(): string;
                }
                PHP
        );

        $this->writeAppFile(
            'Imports/InterfaceImport/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Imports\InterfaceImport;

                use App\Imports\InterfaceImport\Real\Contract;

                /**
                 * @see Contract
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\Imports\InterfaceImport\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\Imports\InterfaceImport\Facade');

        $this->assertStringContainsString('@method static string describe()', $contents);
    }
}
