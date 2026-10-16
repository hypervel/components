<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Responses;

use Hypervel\Ai\Responses\ClassificationResponse;
use Hypervel\Ai\Responses\Data\ChoiceAnswer;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Tests\TestCase;

class ClassificationResponseTest extends TestCase
{
    public function testNumericQuestionKeysRemainAccessibleThroughArrayAccess(): void
    {
        $answer = new ChoiceAnswer('yes', ['yes' => 1.0]);
        $response = new ClassificationResponse(['1' => $answer], new TextUsage, new Meta);

        foreach ($response as $key => $value) {
            $this->assertSame($answer, $response[$key]);
            $this->assertSame($value, $response->answer((string) $key));
        }
    }
}
