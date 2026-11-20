<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit;

use Hypervel\Scout\SearchableSchema;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class SearchableSchemaTest extends TestCase
{
    public function testVectorExpressionWeightsColumnsAndCoalescesNulls(): void
    {
        $this->assertSame(
            "setweight(to_tsvector('english', coalesce(\"title\"::text, '')), 'A') || setweight(to_tsvector('english', coalesce(\"body\"::text, '')), 'D')",
            SearchableSchema::vectorExpression(['title' => 'a', 'body'], 'english')
        );
    }

    public function testVectorExpressionAcceptsSchemaQualifiedLanguages(): void
    {
        $this->assertStringContainsString("to_tsvector('public.english_unaccent'", SearchableSchema::vectorExpression(['title'], 'public.english_unaccent'));
    }

    public function testVectorExpressionRejectsInvalidInput(): void
    {
        foreach ([[[], 'english'], [['title'], "english'); drop table users; --"], [['title' => 'E'], 'english'], [['ti"tle'], 'english']] as [$columns, $language]) {
            try {
                SearchableSchema::vectorExpression($columns, $language);
                $this->fail('Expected an InvalidArgumentException.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
