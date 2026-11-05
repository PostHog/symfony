<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Mailer\Bridge\PostHog\Transport;

use Composer\InstalledVersions;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\LogicException;
use Symfony\Component\Mailer\Header\TrackingHeader;
use Symfony\Component\Mailer\RemoteTemplate;
use Symfony\Component\Mailer\RemoteTemplateEmail;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractApiTransport;
use Symfony\Component\Mailer\Transport\RemoteTemplateTransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\Headers;
use Symfony\Component\Mime\Part\AbstractMultipartPart;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\TextPart;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Sends through the transactional email API of PostHog.
 *
 * The API carries one recipient, its copies, the sender, the reply-to addresses, the subject, the bodies and the attachments.
 * An email that uses anything else, such as custom headers or tags, is refused before any request, so that nothing is dropped.
 *
 * @author Michael Reichenbach <755327+Silthus@users.noreply.github.com>
 */
final class PostHogApiTransport extends AbstractApiTransport implements RemoteTemplateTransportInterface
{
    private const HOST = 'us.posthog.com';
    private const INTEGRATION = 'symfony-mailer';

    /**
     * Headers that the payload already carries or that the API writes itself.
     */
    private const IGNORED_HEADERS = ['from', 'to', 'cc', 'bcc', 'reply-to', 'subject', 'date', 'message-id', 'mime-version', 'x-mailer', 'x-priority', 'x-track'];

    private const OPTION_HEADERS = ['x-posthog-distinct-id', 'x-posthog-template-key', 'x-posthog-idempotency-key', 'x-posthog-capture-engagement'];

    public function __construct(
        #[\SensitiveParameter] private readonly string $key,
        ?HttpClientInterface $client = null,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($client, $dispatcher, $logger);
    }

    public function __toString(): string
    {
        return \sprintf('posthog+api://%s', $this->getEndpoint());
    }

    protected function doSendApi(SentMessage $sentMessage, Email $email, Envelope $envelope): ResponseInterface
    {
        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$this->key,
            'X-PostHog-Integration' => self::INTEGRATION.'/'.self::getVersion(),
        ];
        if (null !== $idempotencyKey = $email->getHeaders()->getHeaderBody('X-PostHog-Idempotency-Key')) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $response = $this->client->request('POST', 'https://'.$this->getEndpoint().'/api/projects/@current/messaging/send/email/', [
            'headers' => $headers,
            'json' => $this->getPayload($email, $envelope),
        ]);

        try {
            $statusCode = $response->getStatusCode();
            $result = $response->toArray(false);
        } catch (DecodingExceptionInterface) {
            throw new HttpTransportException('Unable to send an email: '.$response->getContent(false).\sprintf(' (code %d).', $response->getStatusCode()), $response);
        } catch (TransportExceptionInterface $e) {
            throw new HttpTransportException('Could not reach the remote PostHog server.', $response, 0, $e);
        }

        if (202 !== $statusCode) {
            throw new HttpTransportException('Unable to send an email: '.$this->getErrorMessage($result, $response).\sprintf(' (code %d).', $statusCode), $response);
        }

        $sentMessage->setMessageId($result['message_id']);

        return $response;
    }

    private function getPayload(Email $email, Envelope $envelope): array
    {
        $headers = $email->getHeaders();
        $this->ensureHeadersAreSupported($email);

        if (1 !== \count($to = $email->getTo())) {
            throw self::refuse('multiple_to', 'the API takes exactly one "To" address');
        }

        $this->ensureEnvelopeMatches($email, $envelope);

        $templateKey = $this->getTemplateKey($headers);
        $captureEngagement = $this->getCaptureEngagement($headers);

        $template = $email instanceof RemoteTemplateEmail ? $email->getRemoteTemplate() : null;
        $payload = ['to' => $to[0]->getAddress()] + (null === $template ? $this->getRenderedPayload($email, $templateKey, $captureEngagement) : $this->getTemplatePayload($email, $template));

        if (null !== $distinctId = $headers->getHeaderBody('X-PostHog-Distinct-Id')) {
            $payload['distinct_id'] = $distinctId;
        }

        return $payload;
    }

    private function getRenderedPayload(Email $email, string $templateKey, ?bool $captureEngagement): array
    {
        $this->ensureBodyIsSupported($email);

        $payload = [];

        if ($from = $email->getFrom()) {
            $payload['from'] = self::formatSender($from[0]);
        }

        foreach (['cc' => $email->getCc(), 'bcc' => $email->getBcc(), 'reply_to' => $email->getReplyTo()] as $field => $addresses) {
            if ($addresses) {
                $payload[$field] = array_map(static fn (Address $address): string => $address->getAddress(), $addresses);
            }
        }

        if (null !== $email->getSubject()) {
            $payload['subject'] = $email->getSubject();
        }

        if (null !== $html = self::bodyToString($email->getHtmlBody())) {
            $payload['html'] = $html;
        }

        if (null !== $text = self::bodyToString($email->getTextBody())) {
            $payload['text'] = $text;
        }

        foreach ($email->getAttachments() as $attachment) {
            $payload['attachments'][] = $this->formatAttachment($attachment, $html);
        }

        $payload['template_key'] = $templateKey;

        if (null !== $captureEngagement) {
            $payload['capture_engagement'] = $captureEngagement;
        }

        return $payload;
    }

    /**
     * The template sets the sender, so the "From" address that Symfony requires only builds the envelope.
     * The template key and the engagement capture belong to the rendered form, so they are not sent.
     */
    private function getTemplatePayload(Email $email, RemoteTemplate $template): array
    {
        if (null !== $email->getSubject() || $email->getCc() || $email->getBcc() || $email->getReplyTo() || $email->getAttachments()) {
            throw self::refuse('template_with_content', 'a template sets the subject, the bodies, the copies, the reply-to addresses and the attachments');
        }

        $payload = ['template_id' => $template->getReference()];

        if ($variables = $template->getVariables()) {
            $payload['message_data'] = $variables;
        }

        return $payload;
    }

    private function ensureHeadersAreSupported(Email $email): void
    {
        if (\count($email->getFrom()) > 1) {
            throw self::refuse('unsupported_header', 'the API takes one "From" address');
        }

        foreach ($email->getHeaders()->all() as $name => $header) {
            if ('x-tag' === $name) {
                throw self::refuse('tags', 'the API does not take tags');
            }

            if (str_starts_with($name, 'x-metadata-')) {
                throw self::refuse('metadata', 'the API does not take metadata');
            }

            if ('x-priority' === $name && Email::PRIORITY_NORMAL !== $email->getPriority()) {
                throw self::refuse('unsupported_priority', 'the API sends every email with the normal priority');
            }

            if (!\in_array($name, self::IGNORED_HEADERS, true) && !\in_array($name, self::OPTION_HEADERS, true) && !str_starts_with($name, 'content-')) {
                throw self::refuse('unsupported_header', \sprintf('the API does not take the "%s" header', $header->getName()));
            }
        }
    }

    private function ensureEnvelopeMatches(Email $email, Envelope $envelope): void
    {
        $recipients = static fn (array $addresses): array => array_values(array_unique(array_map(static fn (Address $address): string => $address->getAddress(), $addresses)));
        $expected = $recipients([...$email->getTo(), ...$email->getCc(), ...$email->getBcc()]);
        $actual = $recipients($envelope->getRecipients());
        sort($expected);
        sort($actual);

        if ($envelope->getSender()->getAddress() !== ($email->getFrom()[0] ?? null)?->getAddress() || $expected !== $actual) {
            throw self::refuse('envelope_mismatch', 'the API sends to the recipients of the email, from its sender');
        }
    }

    /**
     * The request carries the text, the HTML and the attachments of the email, so a body set with setBody() can only hold those.
     */
    private function ensureBodyIsSupported(Email $email): void
    {
        $expected = ['plain' => null !== $email->getTextBody(), 'html' => null !== $email->getHtmlBody()];
        $parts = [$email->getBody()];

        while (null !== $part = array_shift($parts)) {
            if ($part instanceof AbstractMultipartPart) {
                array_push($parts, ...$part->getParts());
            } elseif ($part instanceof DataPart) {
                if (!\in_array($part, $email->getAttachments(), true)) {
                    throw self::refuse('unsupported_part', \sprintf('the body holds a "%s/%s" part that is not an attachment of the email', $part->getMediaType(), $part->getMediaSubtype()));
                }
            } elseif ($part instanceof TextPart && ($expected[$part->getMediaSubtype()] ?? false)) {
                $expected[$part->getMediaSubtype()] = false;
            } else {
                throw self::refuse('unsupported_part', \sprintf('the body holds a "%s/%s" part that the API cannot carry', $part->getMediaType(), $part->getMediaSubtype()));
            }
        }
    }

    private function formatAttachment(DataPart $attachment, ?string $html): array
    {
        $formatted = [
            'filename' => $attachment->getFilename() ?? $attachment->getName(),
            'content_type' => $attachment->getContentType(),
            'content' => base64_encode($attachment->getBody()),
        ];

        if (null !== $reference = null === $html ? null : self::getReferencedContentId($attachment, $html)) {
            $formatted['content_id'] = $reference;
        } elseif ('inline' === $attachment->getDisposition()) {
            if (!$attachment->hasContentId()) {
                throw self::refuse('inline_without_cid', \sprintf('the inline part "%s" has no content id and the HTML does not refer to it', $attachment->getName()));
            }

            $formatted['content_id'] = $attachment->getContentId();
        }

        return $formatted;
    }

    private function getTemplateKey(Headers $headers): string
    {
        if (null === $key = $headers->getHeaderBody('X-PostHog-Template-Key')) {
            return self::INTEGRATION;
        }

        if (!preg_match('/^[a-zA-Z0-9_-]{1,400}$/', $key)) {
            throw self::refuse('invalid_template_key', 'the "X-PostHog-Template-Key" header must match [a-zA-Z0-9_-]{1,400}');
        }

        return $key;
    }

    private function getCaptureEngagement(Headers $headers): ?bool
    {
        $tracking = TrackingHeader::fromHeaders($headers);

        if (null !== $tracking && $tracking->getOpens() !== $tracking->getClicks()) {
            throw self::refuse('mixed_tracking', 'the API tracks opens and clicks together');
        }

        if (null !== $value = $headers->getHeaderBody('X-PostHog-Capture-Engagement')) {
            return match ($value) {
                'true' => true,
                'false' => false,
                default => throw self::refuse('unsupported_header', 'the "X-PostHog-Capture-Engagement" header must be "true" or "false"'),
            };
        }

        return $tracking?->getOpens();
    }

    private function getErrorMessage(array $result, ResponseInterface $response): string
    {
        if (!isset($result['detail'])) {
            return $response->getContent(false);
        }

        $codes = array_filter([$result['code'] ?? null, isset($result['attr']) ? \sprintf('field "%s"', $result['attr']) : null]);

        return $codes ? $result['detail'].' ['.implode(', ', $codes).']' : $result['detail'];
    }

    private function getEndpoint(): string
    {
        return ($this->host ?: self::HOST).($this->port ? ':'.$this->port : '');
    }

    private static function refuse(string $code, string $detail): LogicException
    {
        return new LogicException(\sprintf('PostHog cannot send this email: %s (%s).', $code, $detail));
    }

    /**
     * Returns the reference the HTML uses for the part: its name or its content id.
     *
     * The HTML is sent as is, so the API must get the same reference. A content id that Symfony generates when it renders the body would break it.
     */
    private static function getReferencedContentId(DataPart $part, string $html): ?string
    {
        foreach ([$part->getName(), $part->hasContentId() ? $part->getContentId() : null] as $reference) {
            if (null !== $reference && preg_match('/\b(?i:cid:)'.preg_quote($reference, '/').'(?=[\s"\'>)]|$)/', $html)) {
                return $reference;
            }
        }

        return null;
    }

    /**
     * The API parses the sender as an address, so a name with a special character is quoted.
     */
    private static function formatSender(Address $from): string
    {
        if ('' === $name = $from->getName()) {
            return $from->getAddress();
        }

        return (strpbrk($name, '()<>[]:;@\\,."') ? $from->getEncodedName() : $name).' <'.$from->getAddress().'>';
    }

    /**
     * @param resource|string|null $body
     */
    private static function bodyToString(mixed $body): ?string
    {
        if (!\is_resource($body)) {
            return $body;
        }

        if (stream_get_meta_data($body)['seekable']) {
            rewind($body);
        }

        return (string) stream_get_contents($body);
    }

    private static function getVersion(): string
    {
        foreach (['symfony/post-hog-mailer', 'symfony/symfony'] as $package) {
            if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($package) && null !== $version = InstalledVersions::getPrettyVersion($package)) {
                return substr(preg_replace('/[^0-9A-Za-z.+-]/', '-', $version), 0, 40);
            }
        }

        return 'unknown';
    }
}
