<?php

declare(strict_types=1);

namespace Hypervel\Tests\FacadeDocumenter;

class DocTagParsingTest extends FacadeDocumenterTestCase
{
    /**
     * Parse supported tags regardless of docblock line layout.
     */
    public function testParsesTagsRegardlessOfLineLayout(): void
    {
        $this->writeAppFile(
            'DocTags/Mixin.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\DocTags;

                class Mixin
                {
                    public function fromMixin(): int
                    {
                        return 1;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'DocTags/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\DocTags;

                /** @mixin \App\DocTags\Mixin */
                class Proxy
                {
                    /** @internal */
                    public function internalMethod(): string
                    {
                        return 'internal';
                    }

                    /** @deprecated */
                    public function deprecatedMethod(): string
                    {
                        return 'deprecated';
                    }

                    /** @param int $extra */
                    public function dynamicMethod(): string
                    {
                        return 'dynamic';
                    }

                    public function proxyMethod(): string
                    {
                        return 'proxy';
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'DocTags/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\DocTags;

                /** @see \App\DocTags\Proxy */
                class Facade
                {
                }
                PHP
        );

        // Insert tabs after parsing because flexible nowdoc indentation cannot mix tabs with spaces.
        $this->writeAppFile(
            'DocTags/TabbedProxy.php',
            str_replace(
                '__TAB__',
                "\t",
                <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\DocTags;

                /**
                __TAB__ * @mixin \App\DocTags\Mixin
                __TAB__ */
                class TabbedProxy
                {
                    /**
                    __TAB__ * @internal
                    __TAB__ */
                    public function tabbedInternalMethod(): string
                    {
                        return 'internal';
                    }

                    /**
                    __TAB__ * @param int $extra
                    __TAB__ */
                    public function tabbedDynamicMethod(): string
                    {
                        return 'dynamic';
                    }

                    public function tabbedProxyMethod(): string
                    {
                        return 'proxy';
                    }
                }
                PHP
            )
        );

        $this->writeAppFile(
            'DocTags/TabbedFacade.php',
            str_replace(
                '__TAB__',
                "\t",
                <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\DocTags;

                /**
                __TAB__ * @see \App\DocTags\TabbedProxy
                __TAB__ */
                class TabbedFacade
                {
                }
                PHP
            )
        );

        $process = $this->runDocumenter([
            'App\DocTags\Facade',
            'App\DocTags\TabbedFacade',
        ]);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\DocTags\Facade');

        $this->assertStringContainsString('@method static int fromMixin()', $contents);
        $this->assertStringContainsString('@method static string dynamicMethod(int $extra = null)', $contents);
        $this->assertStringContainsString('@method static string proxyMethod()', $contents);
        $this->assertStringNotContainsString('internalMethod', $contents);
        $this->assertStringNotContainsString('deprecatedMethod', $contents);
        $this->assertStringContainsString(' * @see \App\DocTags\Proxy', $contents);
        $this->assertStringNotContainsString(' * @see ' . str_repeat('\\', 2) . 'App\DocTags\Proxy', $contents);

        $tabbedContents = $this->appFileContents('App\DocTags\TabbedFacade');

        $this->assertStringContainsString('@method static int fromMixin()', $tabbedContents);
        $this->assertStringContainsString('@method static string tabbedDynamicMethod(int $extra = null)', $tabbedContents);
        $this->assertStringContainsString('@method static string tabbedProxyMethod()', $tabbedContents);
        $this->assertStringNotContainsString('tabbedInternalMethod', $tabbedContents);
        $this->assertStringContainsString(' * @see \App\DocTags\TabbedProxy', $tabbedContents);
        $this->assertStringNotContainsString(
            ' * @see ' . str_repeat('\\', 2) . 'App\DocTags\TabbedProxy',
            $tabbedContents,
        );
    }
}
