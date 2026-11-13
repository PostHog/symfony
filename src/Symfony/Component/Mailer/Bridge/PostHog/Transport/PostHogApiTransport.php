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

    private const OPTION_HEADERS = ['x-posthog-distinct-id', 'x-posthog-template-key', 'x-posthog-idempotency-key', 'x-posthog-capture-engagement', 'x-posthog-sensitive'];

    /**
     * Part headers that Mime writes itself, such as the Content-ID of a related part, which the request carries as content_id.
     */
    private const HEADERS_MIME_WRITES = ['content-id'];

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

        $files = [];
        $payload = $this->getPayload($email, $envelope, $files);

        if ($files) {
            $this->uploadFiles($files, $headers);
        }

        if (null !== $idempotencyKey = $email->getHeaders()->getHeaderBody('X-PostHog-Idempotency-Key')) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $response = $this->client->request('POST', 'https://'.$this->getEndpoint().'/api/projects/@current/messaging/send/email/', [
            'headers' => $headers,
            'json' => $payload,
        ]);

        $result = $this->getResult($response, 202, 'Unable to send an email');
        $sentMessage->setMessageId($result['message_id']);

        return $response;
    }

    /**
     * Asks PostHog which files it lacks and uploads each of them to the URL it answers, without the secret key.
     *
     * The email then names each file by its SHA-256, so a file that PostHog already has is not uploaded again.
     * The transport does not retry: a failed upload sends no email.
     *
     * @param array<string, array{body: string, filename: ?string}> $files keyed by their SHA-256
     */
    private function uploadFiles(array $files, array $headers): void
    {
        $response = $this->client->request('POST', 'https://'.$this->getEndpoint().'/api/projects/@current/messaging/attachments/uploads/', [
            'headers' => $headers,
            'json' => ['files' => array_map(static fn (string $sha256, array $file): array => ['sha256' => $sha256, 'size' => \strlen($file['body'])], array_keys($files), $files)],
        ]);

        $slots = $this->getResult($response, 200, 'Unable to upload the attachments')['files'] ?? null;

        $uploads = [];
        foreach (array_keys($files) as $i => $sha256) {
            $slot = $slots[$i] ?? null;

            if (!\is_array($slot) || $sha256 !== ($slot['sha256'] ?? null)) {
                throw new HttpTransportException('Unable to upload the attachments: PostHog answered without an upload slot for each file.', $response);
            }

            if ('present' === ($slot['status'] ?? null)) {
                continue;
            }

            if ('upload' !== ($slot['status'] ?? null) || !\is_string($slot['url'] ?? null) || !\is_string($slot['method'] ?? 'PUT') || !\is_array($slot['headers'] ?? null)) {
                throw new HttpTransportException('Unable to upload the attachments: PostHog answered without an upload slot for each file.', $response);
            }

            // the file storage is not the API host, so a plain HTTP URL would send the file unencrypted
            if (!str_starts_with(strtolower($slot['url']), 'https://')) {
                throw new HttpTransportException(\sprintf('Unable to upload the attachment "%s": PostHog named an upload URL that is not https.', $files[$sha256]['filename']), $response);
            }

            $uploads[$sha256] = $slot;
        }

        foreach ($uploads as $sha256 => $slot) {
            $upload = $this->client->request($slot['method'] ?? 'PUT', $slot['url'], [
                'headers' => $slot['headers'],
                'body' => $files[$sha256]['body'],
                'max_redirects' => 0,
            ]);

            $failure = \sprintf('Unable to upload the attachment "%s": ', $files[$sha256]['filename']);

            try {
                $statusCode = $upload->getStatusCode();
                $error = $statusCode < 200 || $statusCode >= 300 ? $upload->getContent(false) : null;
            } catch (TransportExceptionInterface $e) {
                throw new HttpTransportException($failure.'could not reach the file storage of PostHog.', $upload, 0, $e);
            }

            if (null !== $error) {
                preg_match_all('{<(Code|Message)>([^<]{1,500})</\1>}', $error, $matches);

                throw new HttpTransportException($failure.($matches[2] ? implode(', ', $matches[2]) : 'the file storage answered').\sprintf(' (code %d).', $statusCode), $upload);
            }
        }
    }

    private function getResult(ResponseInterface $response, int $expectedStatusCode, string $failure): array
    {
        try {
            $statusCode = $response->getStatusCode();
            $result = $response->toArray(false);
        } catch (DecodingExceptionInterface) {
            throw new HttpTransportException($failure.': '.$response->getContent(false).\sprintf(' (code %d).', $response->getStatusCode()), $response);
        } catch (TransportExceptionInterface $e) {
            throw new HttpTransportException('Could not reach the remote PostHog server.', $response, 0, $e);
        }

        if ($expectedStatusCode !== $statusCode) {
            throw new HttpTransportException($failure.': '.$this->getErrorMessage($result, $response).\sprintf(' (code %d).', $statusCode), $response);
        }

        return $result;
    }

    /**
     * @param array<string, array{body: string, filename: ?string}> $files the files to upload, keyed by their SHA-256
     */
    private function getPayload(Email $email, Envelope $envelope, array &$files): array
    {
        $headers = $email->getHeaders();
        $this->ensureHeadersAreSupported($email);

        if (1 !== \count($to = $email->getTo())) {
            throw self::refuse('multiple_to', 'the API takes exactly one "To" address');
        }

        $this->ensureEnvelopeMatches($email, $envelope);

        $options = [
            'template_key' => $this->getTemplateKey($headers),
            'tracking' => $this->getTracking($headers),
            'sensitive' => self::getBooleanHeader($headers, 'X-PostHog-Sensitive'),
        ];

        $template = $email instanceof RemoteTemplateEmail ? $email->getRemoteTemplate() : null;
        $payload = ['to' => $to[0]->getAddress()] + (null === $template ? $this->getRenderedPayload($email, $options, $files) : $this->getTemplatePayload($email, $template));

        if (null !== $distinctId = $headers->getHeaderBody('X-PostHog-Distinct-Id')) {
            $payload['distinct_id'] = $distinctId;
        }

        return $payload;
    }

    /**
     * @param array<string, array{body: string, filename: ?string}> $files
     */
    private function getRenderedPayload(Email $email, array $options, array &$files): array
    {
        // a stream can only be read once, so its contents are kept for the checks and the request
        if (\is_resource($text = $email->getTextBody())) {
            $email->text(self::bodyToString($text), $email->getTextCharset());
        }

        if (\is_resource($html = $email->getHtmlBody())) {
            $email->html(self::bodyToString($html), $email->getHtmlCharset());
        }

        $this->ensureBodyIsSupported($email);

        $payload = [];

        if ($from = $email->getFrom()) {
            $payload['from'] = $from[0]->toString();
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
            $payload['html'] = $html = self::toUtf8($html, $email->getHtmlCharset(), 'HTML');
        }

        if (null !== $text = self::bodyToString($email->getTextBody())) {
            $payload['text'] = self::toUtf8($text, $email->getTextCharset(), 'text');
        }

        foreach ($email->getAttachments() as $attachment) {
            $payload['attachments'][] = $this->formatAttachment($attachment, $html, $files);
        }

        return $payload + array_filter($options, static fn (mixed $option): bool => null !== $option);
    }

    /**
     * The template sets the sender, so the "From" address that Symfony requires only builds the envelope.
     * The template key, the tracking and the sensitivity belong to the rendered form, so they are not sent: the template has its own.
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

        $seen = [];
        foreach ($email->getHeaders()->all() as $name => $header) {
            if (isset($seen[$name]) && ('x-track' === $name || \in_array($name, self::OPTION_HEADERS, true))) {
                throw self::refuse('unsupported_header', \sprintf('the email has more than one "%s" header', $header->getName()));
            }

            $seen[$name] = true;

            if ('x-tag' === $name) {
                throw self::refuse('tags', 'the API does not take tags');
            }

            if (str_starts_with($name, 'x-metadata-')) {
                throw self::refuse('metadata', 'the API does not take metadata');
            }

            if ('x-priority' === $name && Email::PRIORITY_NORMAL !== $email->getPriority()) {
                throw self::refuse('unsupported_priority', 'the API sends every email with the normal priority');
            }

            if (!\in_array($name, self::IGNORED_HEADERS, true) && !\in_array($name, self::OPTION_HEADERS, true)) {
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
     * The request carries the text, the HTML and the attachments of the email, so a body set with setBody() must hold exactly those.
     */
    private function ensureBodyIsSupported(Email $email): void
    {
        $parts = [$email->getBody()];
        $attachments = $email->getAttachments();
        $expected = self::getBuiltTextParts($email);

        while (null !== $part = array_shift($parts)) {
            if ($names = array_diff($part->getHeaders()->getNames(), self::HEADERS_MIME_WRITES)) {
                throw self::refuse('unsupported_part', \sprintf('the body holds a "%s/%s" part with its own "%s" header, which the API cannot carry', $part->getMediaType(), $part->getMediaSubtype(), reset($names)));
            }

            if ($part instanceof AbstractMultipartPart) {
                array_push($parts, ...$part->getParts());
            } elseif ($part instanceof DataPart) {
                if (false === $i = array_search($part, $attachments, true)) {
                    throw self::refuse('unsupported_part', \sprintf('the body holds a "%s/%s" part that is not an attachment of the email', $part->getMediaType(), $part->getMediaSubtype()));
                }

                unset($attachments[$i]);
            } elseif ($part instanceof TextPart && isset($expected[$subtype = $part->getMediaSubtype()])) {
                if (!self::hasSameText($part, $expected[$subtype])) {
                    throw self::refuse('unsupported_part', \sprintf('the body holds a "%s/%s" part that differs from the email, which the API would send instead', $part->getMediaType(), $subtype));
                }

                unset($expected[$subtype]);
            } else {
                throw self::refuse('unsupported_part', \sprintf('the body holds a "%s/%s" part that the API cannot carry', $part->getMediaType(), $part->getMediaSubtype()));
            }
        }

        if ($expected || $attachments) {
            throw self::refuse('unsupported_part', 'the body does not hold the text, the HTML or an attachment of the email, which the API would send');
        }
    }

    /**
     * Lets Email build the text and HTML parts it would build for this email, so that Email alone decides which references
     * to inline files it rewrites to their content ids. The attachments are cloned, as building the body renames and inlines them.
     *
     * @return array<string, TextPart>
     */
    private static function getBuiltTextParts(Email $email): array
    {
        if (null === $email->getTextBody() && null === $email->getHtmlBody()) {
            return [];
        }

        $built = (new Email())
            ->text($email->getTextBody(), $email->getTextCharset() ?? 'utf-8')
            ->html($email->getHtmlBody(), $email->getHtmlCharset() ?? 'utf-8');

        foreach ($email->getAttachments() as $attachment) {
            $built->addPart(clone $attachment);
        }

        $textParts = [];
        $parts = [$built->getBody()];

        while (null !== $part = array_shift($parts)) {
            if ($part instanceof AbstractMultipartPart) {
                array_push($parts, ...$part->getParts());
            } elseif ($part instanceof TextPart && !$part instanceof DataPart) {
                $textParts[$part->getMediaSubtype()] = $part;
            }
        }

        return $textParts;
    }

    private static function hasSameText(TextPart $part, TextPart $built): bool
    {
        return $part->getBody() === $built->getBody()
            && 0 === strcasecmp($part->getPreparedHeaders()->get('Content-Type')->getBodyAsString(), $built->getPreparedHeaders()->get('Content-Type')->getBodyAsString());
    }

    /**
     * @param array<string, array{body: string, filename: ?string}> $files
     */
    private function formatAttachment(DataPart $attachment, ?string $html, array &$files): array
    {
        $body = $attachment->getBody();
        $formatted = [
            'sha256' => $sha256 = hash('sha256', $body),
            'filename' => $attachment->getFilename() ?? $attachment->getName(),
            'content_type' => $attachment->getContentType(),
        ];
        $files[$sha256] ??= ['body' => $body, 'filename' => $formatted['filename']];

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

        if (!preg_match('/^[a-zA-Z0-9_-]{1,256}$/', $key)) {
            throw self::refuse('invalid_template_key', 'the "X-PostHog-Template-Key" header must match [a-zA-Z0-9_-]{1,256}');
        }

        return $key;
    }

    /**
     * Maps the "X-Track" header onto the tracking of PostHog, and X-PostHog-Capture-Engagement onto its engagement events.
     *
     * A flag that is not set keeps the default of PostHog, and PostHog checks the flags against each other.
     * A header that cannot be read is refused rather than dropped. The deprecated "capture_engagement" field is never sent.
     * A TrackingHeader writes the same text as a plain header set in mailer.headers, so both are read from their text.
     */
    private function getTracking(Headers $headers): ?array
    {
        $flags = ['opens' => null, 'clicks' => null];

        foreach (explode(';', $headers->get(TrackingHeader::NAME)?->getBodyAsString() ?? '') as $part) {
            if ('' === $part = trim($part)) {
                continue;
            }

            [$name, $value] = array_map(trim(...), explode('=', $part, 2)) + [1 => null];

            if (!\array_key_exists($name, $flags) || !\in_array($value, ['true', 'false', 'default'], true)) {
                throw self::refuse('unsupported_header', \sprintf('the "X-Track" header sets "opens" and "clicks" to "true", "false" or "default", not "%s"', $part));
            }

            $flags[$name] = 'default' === $value ? null : 'true' === $value;
        }

        $flags['engagement'] = self::getBooleanHeader($headers, 'X-PostHog-Capture-Engagement');

        return array_filter($flags, static fn (?bool $flag): bool => null !== $flag) ?: null;
    }

    private static function getBooleanHeader(Headers $headers, string $name): ?bool
    {
        return match ($headers->getHeaderBody($name)) {
            null => null,
            'true' => true,
            'false' => false,
            default => throw self::refuse('unsupported_header', \sprintf('the "%s" header must be "true" or "false"', $name)),
        };
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
     * A quoted reference runs to its closing quote, an unquoted one such as url(cid:logo) to whitespace, a quote, ">" or ")".
     */
    private static function getReferencedContentId(DataPart $part, string $html): ?string
    {
        preg_match_all('/(["\'])cid:(.*?)\1|(?<!["\'])\bcid:([^\s"\'>)]+)/i', $html, $matches, \PREG_SET_ORDER);
        $references = array_map(static fn (array $match): string => $match[3] ?? $match[2], $matches);

        foreach ([$part->getName(), $part->hasContentId() ? $part->getContentId() : null] as $reference) {
            if (null !== $reference && \in_array($reference, $references, true)) {
                return $reference;
            }
        }

        return null;
    }

    /**
     * The request is JSON, so a body in another charset is converted to UTF-8.
     */
    private static function toUtf8(string $body, ?string $charset, string $name): string
    {
        if (null === $charset || 'utf-8' === strtolower($charset)) {
            return $body;
        }

        try {
            $converted = mb_convert_encoding($body, 'UTF-8', $charset);
        } catch (\ValueError) {
            $converted = false;
        }

        if (false === $converted) {
            throw self::refuse('unsupported_part', \sprintf('the %s body uses the "%s" charset, which cannot be converted to UTF-8', $name, $charset));
        }

        return $converted;
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
