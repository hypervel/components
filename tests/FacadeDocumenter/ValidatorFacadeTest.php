<?php

declare(strict_types=1);

namespace Hypervel\Tests\FacadeDocumenter;

use Hypervel\Support\Facades\Validator;
use ReflectionClass;

class ValidatorFacadeTest extends FacadeDocumenterTestCase
{
    /**
     * Generate only the validation factory's public surface.
     */
    public function testGeneratesOnlyTheValidationFactorySurface(): void
    {
        $process = $this->runDocumenter(['--lint', Validator::class]);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $docblock = (new ReflectionClass(Validator::class))->getDocComment();

        $this->assertIsString($docblock);
        $this->assertStringContainsString('@method static \Hypervel\Validation\Validator make(', $docblock);
        $this->assertStringContainsString('@method static void fakeDnsLookups(bool $value = true)', $docblock);
        $this->assertStringContainsString('@method static \Hypervel\Validation\PresenceVerifierInterface|null getPresenceVerifier()', $docblock);
        $this->assertStringContainsString('@see \Hypervel\Validation\Factory', $docblock);
        $this->assertStringNotContainsString('@method static bool passes()', $docblock);
        $this->assertStringNotContainsString('@method static \Hypervel\Support\ValidatedInput|array safe(', $docblock);
        $this->assertStringNotContainsString('@method static bool validateAccepted(', $docblock);
        $this->assertStringNotContainsString('@see \Hypervel\Validation\Validator', $docblock);
    }
}
