<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\Notifications\VerifyEmail;
use Hypervel\Routing\Router;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Testbench\TestCase;
use Override;

class VerifyEmailNotificationTest extends TestCase
{
    #[Override]
    protected function defineRoutes(Router $router): void
    {
        $router->get('/email/verify/{id}/{hash}', fn () => 'verified')
            ->name('verification.verify');
    }

    public function testVerificationUrlUsesConfiguredExpiry(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 8, 5, 12));
        config(['auth.verification.expire' => 90]);

        $url = (new VerifyEmailNotificationStub)->verificationUrlFor(new VerifyEmailNotifiableStub);

        $this->assertSame(now()->addMinutes(90)->getTimestamp(), $this->expiresAt($url));
    }

    public function testVerificationUrlUsesFallbackWhenNestedSettingIsOmitted(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 8, 5, 12));
        config(['auth.verification' => []]);

        $url = (new VerifyEmailNotificationStub)->verificationUrlFor(new VerifyEmailNotifiableStub);

        $this->assertSame(now()->addMinutes(60)->getTimestamp(), $this->expiresAt($url));
    }

    public function testMailMessageUsesTranslatedStringMetadata(): void
    {
        $mail = (new VerifyEmail)->toMail(new VerifyEmailNotifiableStub);

        $this->assertSame('Verify your email address', $mail->subject);
        $this->assertSame('Verify Email Address', $mail->actionText);
    }

    /**
     * Read the expiration timestamp from a signed URL.
     */
    private function expiresAt(string $url): int
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return (int) $query['expires'];
    }
}

class VerifyEmailNotificationStub extends VerifyEmail
{
    /**
     * Get the verification URL for testing.
     */
    public function verificationUrlFor(object $notifiable): string
    {
        return $this->verificationUrl($notifiable);
    }
}

class VerifyEmailNotifiableStub
{
    /**
     * Get the model key.
     */
    public function getKey(): int
    {
        return 42;
    }

    /**
     * Get the email used for verification.
     */
    public function getEmailForVerification(): string
    {
        return 'user@example.com';
    }
}
