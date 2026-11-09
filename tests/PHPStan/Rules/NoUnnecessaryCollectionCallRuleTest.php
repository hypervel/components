<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules;

use Hypervel\PHPStan\Methods\ModelScopeMethodResolver;
use Hypervel\PHPStan\Properties\ModelHelper;
use Hypervel\PHPStan\Properties\ModelPropertyHelper;
use Hypervel\PHPStan\Rules\ModelRuleHelper;
use Hypervel\PHPStan\Rules\NoUnnecessaryCollectionCallRule;
use Hypervel\PHPStan\Support\CollectionHelper;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<NoUnnecessaryCollectionCallRule> */
class NoUnnecessaryCollectionCallRuleTest extends RuleTestCase
{
    /**
     * Hypervel: the rule's only and except settings, which one test narrows.
     *
     * @var string[]
     */
    private array $onlyMethods = [];

    /** @var string[] */
    private array $excludeMethods = [];

    protected function getRule(): Rule
    {
        $container = self::getContainer();

        return new NoUnnecessaryCollectionCallRule(
            $this->createReflectionProvider(),
            $container->getByType(ModelRuleHelper::class),
            $container->getByType(ModelHelper::class),
            $container->getByType(ModelPropertyHelper::class),
            $container->getByType(ModelScopeMethodResolver::class),
            $container->getByType(CollectionHelper::class),
            $this->onlyMethods,
            $this->excludeMethods,
        );
    }

    public function testNoFalsePositives(): void
    {
        $this->analyse([__DIR__ . '/data/CorrectCollectionCalls.php'], []);
    }

    public function testNoFalseNegativesEloquent(): void
    {
        // Hypervel: App\User uses SoftDeletes, whose global scope the rule cannot show leaves the result unchanged,
        // so lines 18, 23, 29, 34, 39, 62, 72 and 92 are not reported. Line 45 calls a relation and line 57 a variable,
        // whose queries are not in view.
        // REMOVED: contains() (line 67), where() (78), diff() (84), containsStrict() (97) and sum() (103), whose query
        // forms compare or aggregate values differently.
        $this->analyse([__DIR__ . '/data/UnnecessaryCollectionCallsEloquent.php'], [
            ['Called \'count\' on Hypervel collection, but could have been retrieved as a query with \'count()\'.', 50],
        ]);
    }

    public function testNoFalseNegativesQuery(): void
    {
        // REMOVED: max() (line 15), which aggregates values differently.
        // Hypervel: line 26 is not reported, as a DB::table() query's fetched columns are unknown.
        $this->analyse([__DIR__ . '/data/UnnecessaryCollectionCallsQuery.php'], [
            ['Called \'isNotEmpty\' on Hypervel collection, but could have been retrieved as a query with \'exists()\'.', 20],
        ]);
    }

    public function testOnlyEquivalentQueriesAreReported(): void
    {
        $this->analyse([__DIR__ . '/data/no-unnecessary-collection-call-hypervel.php'], [
            ['Called \'count\' on Hypervel collection, but could have been retrieved as a query with \'count()\'.', 149],
            ['Called \'count\' on Hypervel collection, but could have been retrieved as a query with \'count()\'.', 150],
            ['Called \'first\' on Hypervel collection, but could have been retrieved as a query with \'first()\'.', 151],
            ['Called \'first\' on Hypervel collection, but could have been retrieved as a query with \'first()\'.', 152],
            ['Called \'isEmpty\' on Hypervel collection, but could have been retrieved as a query with \'doesntExist()\'.', 153],
            ['Called \'isNotEmpty\' on Hypervel collection, but could have been retrieved as a query with \'exists()\'.', 154],
            ['Called \'take\' on Hypervel collection, but could have been retrieved as a query with \'take()->get()\'.', 155],
            ['Called \'pluck\' on Hypervel collection, but could have been retrieved as a query with \'pluck()\'.', 156],
            ['Called \'pluck\' on Hypervel collection, but could have been retrieved as a query with \'pluck()\'.', 157],
            ['Called \'pluck\' on Hypervel collection, but could have been retrieved as a query with \'pluck()\'.', 158],
            ['Called \'modelKeys\' on Hypervel collection, but could have been retrieved as a query with \'modelKeys()\'.', 159],
            ['Called \'count\' on Hypervel collection, but could have been retrieved as a query with \'count()\'.', 160],
            ['Called \'count\' on Hypervel collection, but could have been retrieved as a query with \'count()\'.', 161],
            ['Called \'first\' on Hypervel collection, but could have been retrieved as a query with \'first()\'.', 162],
            ['Called \'isEmpty\' on Hypervel collection, but could have been retrieved as a query with \'doesntExist()\'.', 163],
            ['Called \'first\' on Hypervel collection, but could have been retrieved as a query with \'first()\'.', 164],
            ['Called \'count\' on Hypervel collection, but could have been retrieved as a query with \'count()\'.', 165],
            ['Called \'isEmpty\' on Hypervel collection, but could have been retrieved as a query with \'doesntExist()\'.', 166],
            ['Called \'count\' on Hypervel collection, but could have been retrieved as a query with \'count()\'.', 167],
            ['Called \'pluck\' on Hypervel collection, but could have been retrieved as a query with \'pluck()\'.', 168],
            ['Called \'modelKeys\' on Hypervel collection, but could have been retrieved as a query with \'modelKeys()\'.', 169],
        ]);
    }

    public function testOnlyAndExceptSettings(): void
    {
        $this->onlyMethods = ['First', 'COUNT'];
        $this->excludeMethods = ['count'];

        $this->analyse([__DIR__ . '/data/no-unnecessary-collection-call-hypervel.php'], [
            ['Called \'first\' on Hypervel collection, but could have been retrieved as a query with \'first()\'.', 151],
            ['Called \'first\' on Hypervel collection, but could have been retrieved as a query with \'first()\'.', 152],
            ['Called \'first\' on Hypervel collection, but could have been retrieved as a query with \'first()\'.', 162],
            ['Called \'first\' on Hypervel collection, but could have been retrieved as a query with \'first()\'.', 164],
        ]);
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-tests.neon'];
    }
}
