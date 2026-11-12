<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules;

use Hypervel\PHPStan\Rules\NoModelMakeRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

use const DIRECTORY_SEPARATOR;

/** @extends RuleTestCase<NoModelMakeRule> */
class NoModelMakeRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(NoModelMakeRule::class);
    }

    public function testNoFalsePositives(): void
    {
        $this->analyse([
            __DIR__ . '/data/CorrectModelInstantiation.php',
            __DIR__ . '/data/ModelMakeTrait.php',
        ], []);
    }

    public function testModelMake(): void
    {
        $this->analyse([__DIR__ . '/data/ModelMake.php'], [
            ["Called 'Model::make()' which performs unnecessary work, use 'new Model()'.", 15],
            ["Called 'Model::make()' which performs unnecessary work, use 'new Model()'.", 22],
        ]);
    }

    public function testModelMakeOnRelativeName(): void
    {
        $this->analyse([__DIR__ . '/data/ModelMakeRelativeName.php'], [
            ["Called 'Model::make()' which performs unnecessary work, use 'new Model()'.", 13],
            ["Called 'Model::make()' which performs unnecessary work, use 'new Model()'.", 18],
        ]);
    }

    public function testReportsModelMakeCallsInTraitRatherThanClass(): void
    {
        $actualErrors = $this->gatherAnalyserErrors([
            __DIR__ . '/data/ModelMake.php',
            __DIR__ . '/data/ModelMakeTrait.php',
        ]);

        $this->assertCount(3, $actualErrors);
        $this->assertSame(
            "Called 'Model::make()' which performs unnecessary work, use 'new Model()'.",
            $actualErrors[0]->getMessage(),
        );
        $this->assertSame(
            __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'ModelMakeTrait.php (in context of class Hypervel\Tests\PHPStan\Rules\Data\ModelMake)',
            $actualErrors[0]->getFile(),
        );
        $this->assertSame(13, $actualErrors[0]->getLine());
    }

    public function testOnlyExactPlainModelsWithFixedConnectionsAreReported(): void
    {
        $this->analyse([__DIR__ . '/data/no-model-make-hypervel.php'], [
            ["Called 'Model::make()' which performs unnecessary work, use 'new Model()'.", 166],
            ["Called 'Model::make()' which performs unnecessary work, use 'new Model()'.", 167],
            ["Called 'Model::make()' which performs unnecessary work, use 'new Model()'.", 168],
            ["Called 'Model::make()' which performs unnecessary work, use 'new Model()'.", 169],
            ["Called 'Model::make()' which performs unnecessary work, use 'new Model()'.", 170],
            ["Called 'Model::make()' which performs unnecessary work, use 'new Model()'.", 171],
        ]);
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-tests.neon'];
    }
}
