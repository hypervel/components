<?php

declare(strict_types=1);

namespace Hypervel\Mail\Transport;

use Aws\Exception\AwsException;
use Aws\SesV2\SesV2Client;
use Hypervel\Support\Collection;
use Stringable;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Message;

class SesV2Transport extends AbstractTransport implements Stringable
{
    /**
     * Create a new SES transport instance.
     */
    public function __construct(
        protected SesV2Client $ses,
        protected array $options = []
    ) {
        parent::__construct();
    }

    /**
     * Send the given message.
     *
     * @throws TransportException
     */
    protected function doSend(SentMessage $message): void
    {
        $options = $this->options;
        $originalMessage = $message->getOriginalMessage();

        if ($originalMessage instanceof Message) {
            if ($listManagementOptions = $this->listManagementOptions($message)) {
                $options['ListManagementOptions'] = $listManagementOptions;
            }

            if (($tenantName = $this->tenantName($message)) !== null) {
                $options['TenantName'] = $tenantName;
            }

            foreach ($originalMessage->getHeaders()->all() as $header) {
                if ($header instanceof MetadataHeader) {
                    $options['EmailTags'][] = ['Name' => $header->getKey(), 'Value' => $header->getValue()];
                }
            }
        }

        try {
            $result = $this->ses->sendEmail(
                array_merge(
                    $options,
                    [
                        'Source' => $message->getEnvelope()->getSender()->toString(),
                        'Destination' => [
                            'ToAddresses' => (new Collection($message->getEnvelope()->getRecipients()))
                                ->map
                                ->toString()
                                ->values() // @phpstan-ignore method.nonObject (HigherOrderProxy: ->map->toString() returns Collection, not string)
                                ->all(),
                        ],
                        'Content' => [
                            'Raw' => [
                                'Data' => $message->toString(),
                            ],
                        ],
                    ]
                )
            );
        } catch (AwsException $e) {
            $reason = $e->getAwsErrorMessage() ?? $e->getMessage();

            throw new TransportException(
                sprintf('Request to AWS SES V2 API failed. Reason: %s.', $reason),
                is_int($e->getCode()) ? $e->getCode() : 0,
                $e
            );
        }

        $messageId = $result->get('MessageId');

        if ($originalMessage instanceof Message) {
            $originalMessage->getHeaders()->addHeader('X-Message-ID', $messageId);
            $originalMessage->getHeaders()->addHeader('X-SES-Message-ID', $messageId);
        } else {
            // Symfony only derives a message ID from structured messages.
            $message->setMessageId($messageId);
        }
    }

    /**
     * Extract the SES list management options, if applicable.
     */
    protected function listManagementOptions(SentMessage $message): ?array
    {
        /** @var Message $originalMessage */
        $originalMessage = $message->getOriginalMessage();

        if ($header = $originalMessage->getHeaders()->get('X-SES-LIST-MANAGEMENT-OPTIONS')) {
            if (preg_match('/^(contactListName=)*(?<ContactListName>[^;]+)(;\s?topicName=(?<TopicName>.+))?$/ix', $header->getBodyAsString(), $listManagementOptions)) {
                return array_filter($listManagementOptions, fn ($e) => in_array($e, ['ContactListName', 'TopicName'], true), ARRAY_FILTER_USE_KEY);
            }
        }

        return null;
    }

    /**
     * Extract the SES tenant name, if applicable.
     */
    protected function tenantName(SentMessage $message): ?string
    {
        /** @var Message $originalMessage */
        $originalMessage = $message->getOriginalMessage();

        if ($header = $originalMessage->getHeaders()->get('X-SES-TENANT-NAME')) {
            $tenantName = $header->getBodyAsString();

            return $tenantName === '' ? null : $tenantName;
        }

        return null;
    }

    /**
     * Get the Amazon SES V2 client for the SesV2Transport instance.
     */
    public function ses(): SesV2Client
    {
        return $this->ses;
    }

    /**
     * Get the transmission options being used by the transport.
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Set the transmission options being used by the transport.
     *
     * Boot-only. Mutates the shared transport's SES send options; per-request
     * use races across coroutines.
     */
    public function setOptions(array $options): array
    {
        return $this->options = $options;
    }

    /**
     * Get the string representation of the transport.
     */
    public function __toString(): string
    {
        return 'ses-v2';
    }
}
