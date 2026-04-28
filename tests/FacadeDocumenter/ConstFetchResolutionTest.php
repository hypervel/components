<?php

declare(strict_types=1);

namespace Hypervel\Tests\FacadeDocumenter;

class ConstFetchResolutionTest extends FacadeDocumenterTestCase
{
    public function testSingleConstantResolvesToItsValueType()
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

    public function testWildcardConstantResolvesToUnionOfValueTypes()
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

    public function testKeyOfArrayConstantResolvesToKeyTypeUnion()
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

    public function testValueOfArrayConstantResolvesToValueTypeUnion()
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
}
