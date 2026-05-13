<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\FormRequestResolutionTest;

use Hypervel\Foundation\Http\FormRequest;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\TestCase;

class FormRequestResolutionTest extends TestCase
{
    public function testFormRequestSubclassReceivesCurrentRequestDataAcrossMultipleResolutions()
    {
        Route::post('test-route', fn (FormRequestResolutionTestRequest $request) => [
            'value' => $request->input('value'),
        ]);

        $first = $this->withoutExceptionHandling()->postJson('test-route', ['value' => 'first']);
        $second = $this->withoutExceptionHandling()->postJson('test-route', ['value' => 'second']);

        $first->assertOk();
        $second->assertOk();

        $this->assertSame('first', $first->json('value'));
        $this->assertSame(
            'second',
            $second->json('value'),
            'Second request resolved a stale FormRequest instance from the first request — auto-singleton leak.'
        );
    }
}

class FormRequestResolutionTestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
