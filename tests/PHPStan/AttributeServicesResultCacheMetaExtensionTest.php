<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan;

use function copy;
use function mkdir;

class AttributeServicesResultCacheMetaExtensionTest extends ResultCacheTestCase
{
    protected string $fixture = 'AttributeServices';

    /**
     * PHPStan hashes the files of known extensions but not the list of attribute services, so a newly
     * added extension reanalyses unchanged files only through the metadata extension.
     */
    public function testAddedAttributeServiceReanalysesUnchangedFiles(): void
    {
        $applicationPath = $this->copyApplication();
        $extensionsPath = $applicationPath . '/extensions';
        mkdir($extensionsPath);
        $configuration = "attributeServicesDirectories:\n    - {$extensionsPath}";

        $this->assertSame([], $this->diagnostics($this->analyse($applicationPath, $configuration), 0));

        copy(__DIR__ . '/Fixtures/AttributeServices/IdentifierReturnTypeExtension.php', $extensionsPath . '/IdentifierReturnTypeExtension.php');

        $this->assertSame(['Usage.php:14 return.type'], $this->diagnostics($this->analyse($applicationPath, $configuration), 1));

        $process = $this->analyse($applicationPath, $configuration);

        $this->assertSame(['Usage.php:14 return.type'], $this->diagnostics($process, 1));
        $this->assertReanalysed(0, $process);
    }
}
