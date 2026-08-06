<?php

declare(strict_types=1);

namespace Hypervel\Tests\FacadeDocumenter;

class ConstFetchResolutionTest extends FacadeDocumenterTestCase
{
    public function testSingleConstantResolvesToItsValueType(): void
    {
        $this->writeAppFile(
            'ConstFetch/Single/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\ConstFetch\Single;

                class Proxy
                {
                    public const int DEFAULT_CODE = 200;

                    /**
                     * @return Proxy::DEFAULT_CODE
                     */
                    public function statusCode(): mixed
                    {
                        return self::DEFAULT_CODE;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'ConstFetch/Single/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\ConstFetch\Single;

                /**
                 * @see \App\ConstFetch\Single\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\ConstFetch\Single\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\ConstFetch\Single\Facade');

        $this->assertStringContainsString('@method static int statusCode()', $contents);
    }

    public function testWildcardConstantResolvesToUnionOfValueTypes(): void
    {
        $this->writeAppFile(
            'ConstFetch/Wildcard/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\ConstFetch\Wildcard;

                class Proxy
                {
                    public const int HEADER_A = 1;

                    public const int HEADER_B = 2;

                    public const int HEADER_C = 4;

                    /**
                     * @return Proxy::HEADER_*
                     */
                    public function activeHeader(): mixed
                    {
                        return self::HEADER_A;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'ConstFetch/Wildcard/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\ConstFetch\Wildcard;

                /**
                 * @see \App\ConstFetch\Wildcard\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\ConstFetch\Wildcard\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\ConstFetch\Wildcard\Facade');

        // All matching HEADER_* values are ints so the union collapses to 'int'.
        $this->assertStringContainsString('@method static int activeHeader()', $contents);
    }

    public function testKeyOfArrayConstantResolvesToKeyTypeUnion(): void
    {
        $this->writeAppFile(
            'ConstFetch/KeyOf/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\ConstFetch\KeyOf;

                class Proxy
                {
                    public const array MAP = [
                        'alpha' => 1,
                        'beta' => 2,
                        'gamma' => 3,
                    ];

                    /**
                     * @return key-of<Proxy::MAP>
                     */
                    public function label(): mixed
                    {
                        return 'alpha';
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'ConstFetch/KeyOf/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\ConstFetch\KeyOf;

                /**
                 * @see \App\ConstFetch\KeyOf\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\ConstFetch\KeyOf\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\ConstFetch\KeyOf\Facade');

        $this->assertStringContainsString('@method static string label()', $contents);
    }

    public function testValueOfArrayConstantResolvesToValueTypeUnion(): void
    {
        $this->writeAppFile(
            'ConstFetch/ValueOf/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\ConstFetch\ValueOf;

                class Proxy
                {
                    public const array MAP = [
                        'alpha' => 1,
                        'beta' => 2,
                        'gamma' => 3,
                    ];

                    /**
                     * @return value-of<Proxy::MAP>
                     */
                    public function numericValue(): mixed
                    {
                        return 1;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'ConstFetch/ValueOf/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\ConstFetch\ValueOf;

                /**
                 * @see \App\ConstFetch\ValueOf\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\ConstFetch\ValueOf\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\ConstFetch\ValueOf\Facade');

        $this->assertStringContainsString('@method static int numericValue()', $contents);
    }

    public function testConstantOwnersPreferLexicalClassesAndPreserveExplicitGlobalNames(): void
    {
        $this->writeAppFile(
            'ConstFetch/Shadow/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\ConstFetch\Shadow;

                class DateTime
                {
                    public const string VALUE = 'shadow';

                    public const array MAP = [
                        'alpha' => 1,
                        'beta' => 2,
                    ];
                }

                class Proxy
                {
                    /** @return DateTime::VALUE */
                    public function scalarConstant(): mixed
                    {
                        return DateTime::VALUE;
                    }

                    /** @return key-of<DateTime::MAP> */
                    public function mapKey(): mixed
                    {
                        return 'alpha';
                    }

                    /** @return value-of<DateTime::MAP> */
                    public function mapValue(): mixed
                    {
                        return 1;
                    }

                    /** @return \DateTime::ATOM */
                    public function explicitGlobalShadowed(): mixed
                    {
                        return \DateTime::ATOM;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'ConstFetch/Shadow/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\ConstFetch\Shadow;

                /** @see \App\ConstFetch\Shadow\Proxy */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\ConstFetch\Shadow\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\ConstFetch\Shadow\Facade');

        $this->assertStringContainsString('@method static string scalarConstant()', $contents);
        $this->assertStringContainsString('@method static string mapKey()', $contents);
        $this->assertStringContainsString('@method static int mapValue()', $contents);
        $this->assertStringContainsString('@method static string explicitGlobalShadowed()', $contents);
    }

    /**
     * Resolve relative constant owners from the declaring and selected classes.
     */
    public function testRelativeConstantOwnersUsePhpSemantics(): void
    {
        $this->writeAppFile(
            'ConstFetch/Relative/GrandProxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\ConstFetch\Relative;

                class GrandProxy
                {
                    public const VALUE = 'grand';
                }
                PHP
        );

        $this->writeAppFile(
            'ConstFetch/Relative/DeclaringProxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\ConstFetch\Relative;

                class DeclaringProxy extends GrandProxy
                {
                    public const VALUE = 123;

                    /** @return self::VALUE */
                    public function selfConstant(): mixed
                    {
                        return self::VALUE;
                    }

                    /** @return parent::VALUE */
                    public function parentConstant(): mixed
                    {
                        return parent::VALUE;
                    }

                    /** @return static::VALUE */
                    public function staticConstant(): mixed
                    {
                        return static::VALUE;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'ConstFetch/Relative/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\ConstFetch\Relative;

                class Proxy extends DeclaringProxy
                {
                    public const VALUE = true;
                }
                PHP
        );

        $this->writeAppFile(
            'ConstFetch/Relative/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\ConstFetch\Relative;

                /**
                 * @see \App\ConstFetch\Relative\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\ConstFetch\Relative\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\ConstFetch\Relative\Facade');

        $this->assertStringContainsString('@method static int selfConstant()', $contents);
        $this->assertStringContainsString('@method static string parentConstant()', $contents);
        $this->assertStringContainsString('@method static bool staticConstant()', $contents);
    }
}
