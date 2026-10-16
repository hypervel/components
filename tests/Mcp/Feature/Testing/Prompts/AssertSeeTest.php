<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Testing\Prompts\AssertSeeTest;

use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server;
use Hypervel\Mcp\Server\Prompt;
use Hypervel\Mcp\Server\Testing\TestResponse;
use Hypervel\Mcp\Transport\JsonRpcResponse;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\ExpectationFailedException;

class HotelP extends Server
{
    protected array $prompts = [
        BookingPrompt::class,
    ];
}

class BookingPrompt extends Prompt
{
    /**
     * Return the booking result for the requested date.
     */
    public function handle(Request $request): Response|array|string
    {
        $date = $request->date('date');

        if ($date?->isPast()) {
            return Response::error('The booking date cannot be in the past.');
        }

        if ($date?->year === 2999) {
            return [
                'You must be joking! That date is too far in the future.',
                'Please select a more reasonable date.',
            ];
        }

        return 'Your booking is confirmed!';
    }

    /**
     * Determine whether this prompt is available for the request.
     */
    public function shouldRegister(Request $request): bool
    {
        return $request->boolean('register', true);
    }
}

class AssertSeeTest extends TestCase
{
    public function testMayAssertThatTextIsSeenWhenReturningStringContent(): void
    {
        $response = HotelP::prompt(BookingPrompt::class);

        $response->assertSee('Your booking is confirmed!')
            ->assertDontSee('The booking date cannot be in the past.')
            ->assertDontSee('Please select a more reasonable date.');
    }

    public function testMayAssertThatTextIsSeenWhenProvidingArguments(): void
    {
        $response = HotelP::prompt(BookingPrompt::class, ['date' => now()->addDay()->toDateString()]);

        $response->assertSee('Your booking is confirmed!')
            ->assertDontSee('The booking date cannot be in the past.')
            ->assertDontSee('Please select a more reasonable date.');
    }

    public function testMayAssertThatTextIsSeenWhenProvidingArgumentsThatAreWrong(): void
    {
        $response = HotelP::prompt(BookingPrompt::class, ['date' => now()->subDay()->toDateString()]);

        $response
            ->assertSee('The booking date cannot be in the past.')
            ->assertDontSee('Your booking is confirmed!')
            ->assertDontSee('Please select a more reasonable date.');
    }

    public function testFailsToAssertThatTextIsSeenWhenNotPresent(): void
    {
        $response = HotelP::prompt(BookingPrompt::class);

        $this->expectException(ExpectationFailedException::class);
        $response->assertSee('This text is not present');
    }

    public function testFailsToAssertThatTextIsNotSeenWhenItIsPresent(): void
    {
        $response = HotelP::prompt(BookingPrompt::class);

        $this->expectException(ExpectationFailedException::class);
        $response->assertDontSee('Your booking is confirmed!');
    }

    public function testMayAssertThatTextIsSeenWhenReturningArrayContent(): void
    {
        $response = HotelP::prompt(BookingPrompt::class, ['date' => '2999-01-01']);

        $response
            ->assertSee('That date is too far in the future')
            ->assertSee('Please select a more reasonable date.')
            ->assertDontSee('Your booking is confirmed!');
    }

    public function testFailsIfThePromptIsNotRegistered(): void
    {
        $response = HotelP::prompt(new class extends Prompt {
            protected string $name = 'unknown/prompt';

            /**
             * Return content for an unavailable prompt.
             */
            public function handle(): string
            {
                return 'This should not be possible.';
            }
        });

        $response->assertHasErrors([
            'Prompt [unknown/prompt] not found.',
        ]);
    }

    public function testFailsIfThePromptIsNotRegisteredDueToTheShouldRegisterMethod(): void
    {
        $response = HotelP::prompt(BookingPrompt::class, ['register' => false]);

        $response->assertHasErrors([
            'Prompt [booking-prompt] not found',
        ]);
    }

    #[DataProvider('zeroResponses')]
    public function testZeroTextIsVisibleToBothAssertions(JsonRpcResponse $message): void
    {
        $response = new TestResponse(new BookingPrompt, $message);

        $response->assertSee('0');

        $this->expectException(ExpectationFailedException::class);
        $response->assertDontSee('0');
    }

    /**
     * Provide zero-valued result and error text.
     */
    public static function zeroResponses(): iterable
    {
        yield 'content' => [JsonRpcResponse::result('test', ['messages' => [
            ['role' => 'user', 'content' => ['type' => 'text', 'text' => '0']],
        ]])];
        yield 'error' => [JsonRpcResponse::error('test', -32603, '0')];
    }
}
