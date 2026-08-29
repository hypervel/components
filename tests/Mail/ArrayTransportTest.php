<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mail;

use Hypervel\Mail\Transport\ArrayTransport;
use Hypervel\Mail\Transport\ArrayTransportMessageStore;
use Hypervel\Tests\TestCase;
use Symfony\Component\Mime\Email;
use WeakReference;

use function Hypervel\Coroutine\parallel;
use function Hypervel\Coroutine\run;

class ArrayTransportTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testMessagesAndFlushAreScopedToTheTransport(): void
    {
        $first = new ArrayTransport;
        $second = new ArrayTransport;

        $first->send($this->message('first'));
        $second->send($this->message('second'));

        $this->assertSame(['first'], $this->subjects($first));
        $this->assertSame(['second'], $this->subjects($second));
        $this->assertCount(0, $first->flush());
        $this->assertCount(0, $first->messages());
        $this->assertSame(['second'], $this->subjects($second));
    }

    public function testMessagesAreIsolatedBetweenConcurrentExecutions(): void
    {
        $transport = new ArrayTransport;
        $subjects = [];

        run(function () use ($transport, &$subjects): void {
            // The hooked sleep is the only yield in these callbacks, so both children
            // send before either reads instead of each running to completion in turn.
            $subjects = parallel([
                function () use ($transport): array {
                    $transport->send($this->message('first'));
                    usleep(5_000);

                    return $this->subjects($transport);
                },
                function () use ($transport): array {
                    $transport->send($this->message('second'));
                    usleep(5_000);

                    return $this->subjects($transport);
                },
            ]);
        });

        $this->assertSame([['first'], ['second']], $subjects);
        $this->assertCount(0, $transport->messages());
    }

    public function testReplicatedContextStartsWithAnIndependentSnapshot(): void
    {
        $transport = new ArrayTransport;
        $childSubjects = [];
        $parentSubjects = [];

        run(function () use ($transport, &$childSubjects, &$parentSubjects): void {
            $transport->send($this->message('parent'));

            [$childSubjects] = parallel([
                function () use ($transport): array {
                    $transport->send($this->message('child'));

                    return $this->subjects($transport);
                },
            ], copyContext: true);

            $parentSubjects = $this->subjects($transport);
        });

        $this->assertSame(['parent', 'child'], $childSubjects);
        $this->assertSame(['parent'], $parentSubjects);
    }

    public function testMessageStoreDoesNotRetainReleasedTransports(): void
    {
        $store = new ArrayTransportMessageStore;
        $transport = new ArrayTransport;
        $reference = WeakReference::create($transport);

        $store->messagesFor($transport);
        unset($transport);
        gc_collect_cycles();

        $this->assertNull($reference->get());
    }

    /**
     * Create a test message.
     */
    protected function message(string $subject): Email
    {
        return (new Email)
            ->from('sender@hypervel.org')
            ->to('recipient@hypervel.org')
            ->subject($subject)
            ->text($subject);
    }

    /**
     * Retrieve the stored message subjects.
     *
     * @return array<int, string>
     */
    protected function subjects(ArrayTransport $transport): array
    {
        return $transport->messages()
            ->map(fn ($message): string => $message->getOriginalMessage()->getSubject())
            ->all();
    }
}
