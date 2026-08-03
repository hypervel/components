<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Contracts\Translation\HasLocalePreference;
use Hypervel\Mail\Mailable;
use Hypervel\Mail\MailManager;
use Hypervel\Support\Testing\Fakes\MailFake;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use LogicException;
use Mockery as m;
use PHPUnit\Framework\ExpectationFailedException;
use UnitEnum;

class SupportTestingMailFakeTest extends TestCase
{
    private MailManager $mailManager;

    private MailFake $fake;

    private MailableStub $mailable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailManager = m::mock(MailManager::class, function (MailManager $mock): void {
            $mock->shouldReceive('getDefaultDriver')
                ->byDefault()
                ->andReturn('smtp');
        });
        $this->fake = new MailFake($this->mailManager);
        $this->mailable = new MailableStub;
    }

    public function testAssertSent(): void
    {
        try {
            $this->fake->assertSent(MailableStub::class);
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected [Hypervel\Tests\Support\MailableStub] mailable was not sent.', $e->getMessage());
        }

        $this->fake->to('taylor@laravel.com')->send($this->mailable);

        $this->fake->assertSent(MailableStub::class);
    }

    public function testAssertSentTo(): void
    {
        try {
            $this->fake->assertSent(MailableStub::class, 'taylor@laravel.com');
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected [Hypervel\Tests\Support\MailableStub] mailable was not sent to address [taylor@laravel.com].', $e->getMessage());
        }

        $this->fake->to('taylor@laravel.com')->send($this->mailable);

        $this->fake->assertSent(MailableStub::class, 'taylor@laravel.com');
    }

    public function testAssertSentToMultiple(): void
    {
        $this->fake->to('dries@laravel.com')->send($this->mailable);
        $this->fake->to('taylor@laravel.com')->send($this->mailable);

        $this->fake->to(['nuno@laravel.com', 'jess@laravel.com'])->send($this->mailable);

        $this->fake->assertSent(MailableStub::class, 3);
        $this->fake->assertSent(
            MailableStub::class,
            ['taylor@laravel.com', 'dries@laravel.com', 'nuno@laravel.com', 'jess@laravel.com']
        );
    }

    public function testAssertSentWhenRecipientHasPreferredLocale(): void
    {
        $user = new LocalizedRecipientStub;

        $this->fake->to($user)->send($this->mailable);

        $this->fake->assertSent(MailableStub::class, function ($mail) use ($user) {
            return $mail->hasTo($user) && $mail->locale === 'au';
        });
    }

    public function testAssertTo(): void
    {
        $this->fake->to('taylor@laravel.com')->send($this->mailable);

        $this->fake->assertSent(MailableStub::class, function ($mail) {
            return $mail->hasTo('taylor@laravel.com');
        });
    }

    public function testAssertCc(): void
    {
        $this->fake->cc('taylor@laravel.com')->send($this->mailable);

        $this->fake->assertSent(MailableStub::class, function ($mail) {
            return $mail->hasCc('taylor@laravel.com');
        });
    }

    public function testAssertBcc(): void
    {
        $this->fake->bcc('taylor@laravel.com')->send($this->mailable);

        $this->fake->assertSent(MailableStub::class, function ($mail) {
            return $mail->hasBcc('taylor@laravel.com');
        });
    }

    public function testAssertNotSent(): void
    {
        $this->fake->assertNotSent(MailableStub::class);

        $this->fake->to('taylor@laravel.com')->send($this->mailable);

        try {
            $this->fake->assertNotSent(MailableStub::class);
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The unexpected [Hypervel\Tests\Support\MailableStub] mailable was sent.', $e->getMessage());
        }
    }

    public function testAssertNotSentWithClosure(): void
    {
        $callback = function (MailableStub $mail) {
            return $mail->hasTo('taylor@laravel.com');
        };

        $this->fake->assertNotSent($callback);

        $this->fake->to('taylor@laravel.com')->send($this->mailable);

        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageMatches('/The unexpected \[' . preg_quote(MailableStub::class, '/') . '\] mailable was sent./m');

        $this->fake->assertNotSent($callback);
    }

    public function testAssertNotSentWithString(): void
    {
        $this->fake->assertNotSent(MailableStub::class, 'taylor@laravel.com');

        $this->fake->to('taylor@laravel.com')->send($this->mailable);

        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('The unexpected [' . MailableStub::class . '] mailable was sent to address [taylor@laravel.com].');

        $this->fake->assertNotSent(MailableStub::class, 'taylor@laravel.com');
    }

    public function testAssertNotSentWithArray(): void
    {
        $this->fake->assertNotSent(MailableStub::class, ['taylor@laravel.com', 'dries@laravel.com']);

        $this->fake->to('dries@laravel.com')->send($this->mailable);

        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('The unexpected [' . MailableStub::class . '] mailable was sent to address [dries@laravel.com].');

        $this->fake->assertNotSent(MailableStub::class, ['taylor@laravel.com', 'dries@laravel.com']);
    }

    public function testAssertSentTimes(): void
    {
        $this->fake->to('taylor@laravel.com')->send($this->mailable);
        $this->fake->to('taylor@laravel.com')->send($this->mailable);

        try {
            $this->fake->assertSent(MailableStub::class, 1);
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected [Hypervel\Tests\Support\MailableStub] mailable was sent 2 times instead of 1 time.', $e->getMessage());
        }

        $this->fake->assertSent(MailableStub::class, 2);
    }

    public function testAssertSentCount(): void
    {
        $this->fake->to('taylor@laravel.com')->send($this->mailable);
        $this->fake->to('taylor@laravel.com')->send($this->mailable);

        try {
            $this->fake->assertSentCount(1);
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The total number of mailables sent was 2 instead of 1.', $e->getMessage());
        }

        $this->fake->assertSentCount(2);
    }

    public function testAssertQueued(): void
    {
        try {
            $this->fake->assertQueued(MailableStub::class);
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected [Hypervel\Tests\Support\MailableStub] mailable was not queued.', $e->getMessage());
        }

        $this->fake->to('taylor@laravel.com')->queue($this->mailable);

        $this->fake->assertQueued(MailableStub::class);
    }

    public function testAssertQueuedTo(): void
    {
        try {
            $this->fake->assertQueued(MailableStub::class, 'taylor@laravel.com');
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected [Hypervel\Tests\Support\MailableStub] mailable was not queued to address [taylor@laravel.com].', $e->getMessage());
        }

        $this->fake->to('taylor@laravel.com')->queue($this->mailable);

        $this->fake->assertQueued(MailableStub::class, 'taylor@laravel.com');
    }

    public function testAssertQueuedToMultiple(): void
    {
        $this->fake->to('dries@laravel.com')->queue($this->mailable);
        $this->fake->to('taylor@laravel.com')->queue($this->mailable);

        $this->fake->to(['nuno@laravel.com', 'jess@laravel.com'])->queue($this->mailable);

        $this->fake->assertQueued(MailableStub::class, 3);
        $this->fake->assertQueued(
            MailableStub::class,
            ['taylor@laravel.com', 'dries@laravel.com', 'nuno@laravel.com', 'jess@laravel.com']
        );
    }

    public function testAssertQueuedTimes(): void
    {
        $this->fake->to('taylor@laravel.com')->queue($this->mailable);
        $this->fake->to('taylor@laravel.com')->queue($this->mailable);

        try {
            $this->fake->assertQueued(MailableStub::class, 1);
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected [Hypervel\Tests\Support\MailableStub] mailable was queued 2 times instead of 1 time.', $e->getMessage());
        }

        $this->fake->assertQueued(MailableStub::class, 2);
    }

    public function testAssertQueuedTimesCalledDirectly(): void
    {
        $this->fake->to('taylor@laravel.com')->queue($this->mailable);
        $this->fake->to('taylor@laravel.com')->queue($this->mailable);

        try {
            $this->fake->assertQueuedTimes(MailableStub::class, 1);
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected [Hypervel\Tests\Support\MailableStub] mailable was queued 2 times instead of 1 time.', $e->getMessage());
        }

        $this->fake->assertQueuedTimes(MailableStub::class, 2);
    }

    public function testAssertNotQueuedWithString(): void
    {
        $this->fake->assertNotQueued(MailableStub::class, 'taylor@laravel.com');

        $this->fake->to('taylor@laravel.com')->queue($this->mailable);

        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('The unexpected [' . MailableStub::class . '] mailable was queued to address [taylor@laravel.com].');

        $this->fake->assertNotQueued(MailableStub::class, 'taylor@laravel.com');
    }

    public function testAssertNotQueuedWithArray(): void
    {
        $this->fake->assertNotQueued(MailableStub::class, ['taylor@laravel.com', 'dries@laravel.com']);

        $this->fake->to('dries@laravel.com')->queue($this->mailable);

        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('The unexpected [' . MailableStub::class . '] mailable was queued to address [dries@laravel.com].');

        $this->fake->assertNotQueued(MailableStub::class, ['taylor@laravel.com', 'dries@laravel.com']);
    }

    public function testAssertQueuedCount(): void
    {
        $this->fake->to('taylor@laravel.com')->queue($this->mailable);
        $this->fake->to('taylor@laravel.com')->queue($this->mailable);

        try {
            $this->fake->assertQueuedCount(1);
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The total number of mailables queued was 2 instead of 1.', $e->getMessage());
        }

        $this->fake->assertQueuedCount(2);
    }

    public function testSendQueuesAMailableThatShouldBeQueued(): void
    {
        $this->fake->to('taylor@laravel.com')->send(new QueueableMailableStub);

        $this->fake->assertQueued(QueueableMailableStub::class);

        try {
            $this->fake->assertSent(QueueableMailableStub::class);
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected [Hypervel\Tests\Support\QueueableMailableStub] mailable was not sent.', $e->getMessage());
        }
    }

    public function testAssertNothingSent(): void
    {
        $this->fake->assertNothingSent();

        $this->fake->to('taylor@laravel.com')->send($this->mailable);

        try {
            $this->fake->assertNothingSent();
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString("The following mailables were sent unexpectedly:\n\n- Hypervel\\Tests\\Support\\MailableStub", $e->getMessage());
        }
    }

    public function testAssertNothingQueued(): void
    {
        $this->fake->assertNothingQueued();

        $this->fake->to('taylor@laravel.com')->queue($this->mailable);

        try {
            $this->fake->assertNothingQueued();
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString("The following mailables were queued unexpectedly:\n\n- Hypervel\\Tests\\Support\\MailableStub", $e->getMessage());
        }
    }

    public function testAssertOutgoingCount(): void
    {
        $this->fake->assertNothingOutgoing();

        $this->fake->to('taylor@laravel.com')->queue($this->mailable);

        try {
            $this->fake->assertOutgoingCount(2);
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The total number of outgoing mailables was 1 instead of 2.', $e->getMessage());
        }

        $this->fake->to('taylor@laravel.com')->send($this->mailable);

        $this->fake->assertOutgoingCount(2);
    }

    public function testAssertQueuedWithClosure(): void
    {
        $this->fake->to($user = new LocalizedRecipientStub)->queue($this->mailable);

        $this->fake->assertQueued(function (MailableStub $mail) use ($user) {
            return $mail->hasTo($user);
        });
    }

    public function testAssertSentWithClosure(): void
    {
        $this->fake->to($user = new LocalizedRecipientStub)->send($this->mailable);

        $this->fake->assertSent(function (MailableStub $mail) use ($user) {
            return $mail->hasTo($user);
        });
    }

    public function testMissingMethodsAreForwarded(): void
    {
        $this->mailManager->shouldReceive('foo')->andReturn('bar');

        $this->assertSame('bar', $this->fake->foo());
    }

    public function testAssertMailer(): void
    {
        $this->fake->to('taylor@laravel.com')->send($this->mailable);

        $this->fake->assertSent(MailableStub::class, function ($mail) {
            return $mail->usesMailer('smtp');
        });

        $this->fake->mailer('ses')->to('taylor@laravel.com')->send($this->mailable);

        $this->fake->assertSent(MailableStub::class, function ($mail) {
            return $mail->usesMailer('ses');
        });

        $this->fake->mailer('sendgrid')->to('taylor@laravel.com')->queue($this->mailable);

        $this->fake->assertQueued(MailableStub::class, function ($mail) {
            return $mail->usesMailer('sendgrid');
        });

        $this->fake->mailer('mailjet')->to('taylor@laravel.com')->queue($this->mailable);

        $this->fake->assertQueued(MailableStub::class, function ($mail) {
            return $mail->usesMailer('mailjet');
        });
    }

    public function testDriverMethod(): void
    {
        $this->fake->driver('ses')->to('taylor@laravel.com')->send($this->mailable);

        $this->fake->assertSent(MailableStub::class, function ($mail) {
            return $mail->hasTo('taylor@laravel.com')
                && $mail->usesMailer('ses');
        });

        $this->fake->driver('sendgrid')->to('taylor@laravel.com')->queue($this->mailable);

        $this->fake->assertQueued(MailableStub::class, function ($mail) {
            return $mail->hasTo('taylor@laravel.com')
                && $mail->usesMailer('sendgrid');
        });

        $this->fake->driver('mailjet')->to('taylor@laravel.com')->queue($this->mailable);

        $this->fake->assertQueued(MailableStub::class, function ($mail) {
            return $mail->hasTo('taylor@laravel.com')
                && $mail->usesMailer('mailjet');
        });
    }

    public function testSendNowSendsQueueableMailableSynchronouslyWithPendingRecipients(): void
    {
        $this->fake->to('taylor@laravel.com')->sendNow(new QueueableMailableStub);

        $this->fake->assertSent(
            QueueableMailableStub::class,
            fn (QueueableMailableStub $mailable): bool => $mailable->hasTo('taylor@laravel.com')
        );
        $this->fake->assertNotQueued(QueueableMailableStub::class);
    }

    public function testDefaultMailerIsResolvedWhenEachOperationOccurs(): void
    {
        $manager = m::mock(MailManager::class);
        $manager->shouldReceive('getDefaultDriver')->twice()->andReturn('smtp', 'ses');
        $fake = new MailFake($manager);

        $first = new MailableStub;
        $second = new MailableStub;

        $fake->send($first);
        $fake->send($second);

        $this->assertTrue($first->usesMailer('smtp'));
        $this->assertTrue($second->usesMailer('ses'));
    }

    public function testExplicitMailerSelectionIsConsumedOnce(): void
    {
        $first = new MailableStub;
        $second = new MailableStub;

        $this->fake->mailer('ses')->send($first);
        $this->fake->send($second);

        $this->assertTrue($first->usesMailer('ses'));
        $this->assertTrue($second->usesMailer('smtp'));
    }

    public function testMailerSelectionNormalizesNullEmptyZeroAndEnums(): void
    {
        $null = new MailableStub;
        $empty = new MailableStub;
        $zero = new MailableStub;
        $enum = new MailableStub;

        $this->fake->mailer(null)->send($null);
        $this->fake->mailer('')->send($empty);
        $this->fake->mailer('0')->send($zero);
        $this->fake->mailer(MailFakeMailerName::Transactional)->send($enum);

        $this->assertTrue($null->usesMailer('smtp'));
        $this->assertTrue($empty->usesMailer('smtp'));
        $this->assertTrue($zero->usesMailer('0'));
        $this->assertTrue($enum->usesMailer('transactional'));
    }

    public function testInvalidViewsConsumeExplicitMailerSelection(): void
    {
        $this->fake->mailer('ses')->send('mail.view');

        $mailable = new MailableStub;
        $this->fake->send($mailable);

        $this->assertTrue($mailable->usesMailer('smtp'));
    }

    public function testInvalidQueuedViewsThrowAndConsumeExplicitMailerSelection(): void
    {
        try {
            $this->fake->mailer('ses')->queue('mail.view');
            $this->fail('Expected invalid queued view to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Only mailables may be queued.', $exception->getMessage());
        }

        $mailable = new MailableStub;
        $this->fake->send($mailable);

        $this->assertTrue($mailable->usesMailer('smtp'));
    }

    public function testQueueMethodsPreserveMailerAndQueueSelection(): void
    {
        $queued = new QueueableMailableStub;
        $delayed = new QueueableMailableStub;

        $this->fake->mailer('ses')->queue($queued, 'emails');
        $this->fake->mailer('mailgun')->later(30, $delayed, MailFakeQueueName::Transactional);

        $this->assertTrue($queued->usesMailer('ses'));
        $this->assertSame('emails', $queued->queue);
        $this->assertTrue($delayed->usesMailer('mailgun'));
        $this->assertSame('transactional-mail', $delayed->queue);
    }

    public function testFailedQueueSelectionConsumesExplicitMailer(): void
    {
        try {
            $this->fake->mailer('ses')->queue(new FailingQueueMailableStub, 'emails');
            $this->fail('Expected queue selection to fail.');
        } catch (LogicException $exception) {
            $this->assertSame('Queue selection failed.', $exception->getMessage());
        }

        $mailable = new MailableStub;
        $this->fake->send($mailable);

        $this->assertTrue($mailable->usesMailer('smtp'));
    }

    public function testRawHtmlAndPlainConsumeMailerWithoutCallingTheRealManager(): void
    {
        $this->mailManager->shouldNotReceive('raw');
        $this->mailManager->shouldNotReceive('html');
        $this->mailManager->shouldNotReceive('plain');

        $callback = static function (): void {
        };

        $this->fake->mailer('ses')->raw('raw', $callback);
        $rawNext = new MailableStub;
        $this->fake->send($rawNext);

        $this->fake->mailer('ses')->html('<p>html</p>', $callback);
        $htmlNext = new MailableStub;
        $this->fake->send($htmlNext);

        $this->fake->mailer('ses')->plain('mail.view', [], $callback);
        $plainNext = new MailableStub;
        $this->fake->send($plainNext);

        $this->assertTrue($rawNext->usesMailer('smtp'));
        $this->assertTrue($htmlNext->usesMailer('smtp'));
        $this->assertTrue($plainNext->usesMailer('smtp'));
    }

    public function testQueueHelpersDoNotCallTheRealManager(): void
    {
        $this->mailManager->shouldNotReceive('onQueue');
        $this->mailManager->shouldNotReceive('queueOn');
        $this->mailManager->shouldNotReceive('laterOn');

        $onQueueString = new QueueableMailableStub;
        $onQueueEnum = new QueueableMailableStub;
        $queueOnString = new QueueableMailableStub;
        $queueOnEnum = new QueueableMailableStub;
        $laterOnString = new QueueableMailableStub;
        $laterOnEnum = new QueueableMailableStub;

        $this->fake->onQueue('on-queue', $onQueueString);
        $this->fake->onQueue(MailFakeQueueName::Transactional, $onQueueEnum);
        $this->fake->queueOn('queue-on', $queueOnString);
        $this->fake->queueOn(MailFakeQueueName::Transactional, $queueOnEnum);
        $this->fake->laterOn('later-on', 30, $laterOnString);
        $this->fake->laterOn(MailFakeQueueName::Transactional, 30, $laterOnEnum);

        $this->assertSame('on-queue', $onQueueString->queue);
        $this->assertSame('transactional-mail', $onQueueEnum->queue);
        $this->assertSame('queue-on', $queueOnString->queue);
        $this->assertSame('transactional-mail', $queueOnEnum->queue);
        $this->assertSame('later-on', $laterOnString->queue);
        $this->assertSame('transactional-mail', $laterOnEnum->queue);
        $this->fake->assertQueuedCount(6);
    }

    public function testNamedPendingRecipientsMatchTheRealMailer(): void
    {
        $to = new MailableStub;
        $cc = new MailableStub;
        $bcc = new MailableStub;

        $this->fake->to('to@laravel.com', 'To Name')->send($to);
        $this->fake->cc('cc@laravel.com', 'Cc Name')->send($cc);
        $this->fake->bcc('bcc@laravel.com', 'Bcc Name')->send($bcc);

        $this->assertTrue($to->hasTo('to@laravel.com', 'To Name'));
        $this->assertTrue($cc->hasCc('cc@laravel.com', 'Cc Name'));
        $this->assertTrue($bcc->hasBcc('bcc@laravel.com', 'Bcc Name'));
    }

    public function testShouldQueueSendResolvesTheDefaultMailerOnce(): void
    {
        $manager = m::mock(MailManager::class);
        $manager->shouldReceive('getDefaultDriver')->once()->andReturn('smtp');
        $fake = new MailFake($manager);
        $mailable = new QueueableMailableStub;

        $fake->send($mailable);

        $this->assertTrue($mailable->usesMailer('smtp'));
        $fake->assertQueued(QueueableMailableStub::class);
    }
}

class MailableStub extends Mailable
{
    public string $framework = 'Hypervel';

    protected string $version = '6.0';

    /**
     * Build the message.
     */
    public function build(): void
    {
        $this->with('first_name', 'Taylor')
            ->withLastName('Otwell');
    }
}

class QueueableMailableStub extends Mailable implements ShouldQueue
{
    use Queueable;

    public string $framework = 'Hypervel';

    protected string $version = '6.0';

    /**
     * Build the message.
     */
    public function build(): void
    {
        $this->with('first_name', 'Taylor')
            ->withLastName('Otwell');
    }
}

class LocalizedRecipientStub implements HasLocalePreference
{
    public string $email = 'taylor@laravel.com';

    public function preferredLocale(): string
    {
        return 'au';
    }
}

class FailingQueueMailableStub extends Mailable
{
    use Queueable;

    public function onQueue(UnitEnum|string|null $queue): static
    {
        throw new LogicException('Queue selection failed.');
    }
}

enum MailFakeMailerName: string
{
    case Transactional = 'transactional';
}

enum MailFakeQueueName: string
{
    case Transactional = 'transactional-mail';
}
