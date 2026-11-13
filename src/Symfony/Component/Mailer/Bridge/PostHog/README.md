PostHog Bridge
==============

Provides [PostHog](https://posthog.com) transactional email integration for Symfony Mailer.

Configuration example:

```env
# API, PostHog US Cloud
MAILER_DSN=posthog+api://SECRET_KEY@default

# same thing, shorter
MAILER_DSN=posthog://SECRET_KEY@default

# PostHog EU Cloud
MAILER_DSN=posthog+api://SECRET_KEY@eu.posthog.com

# a self-hosted instance
MAILER_DSN=posthog+api://SECRET_KEY@posthog.example.com
```

where:
 - `SECRET_KEY` is a project secret key (`phs_...`) with the `transactional_email:write` scope, not the project API key used to capture events
 - the host in place of `default` is the app host of your instance; `default` stands for `us.posthog.com`

PostHog has no SMTP relay, so the bridge only provides an API transport.

The API transport carries one "To" address, the "Cc", "Bcc" and "Reply-To" addresses, the sender, the subject, the text and HTML bodies, attachments and inline images (referenced by their `cid`). The API takes bare addresses with ASCII domains for recipients, so their display names are not sent and a domain such as `bücher.example` is sent in Punycode; the display name of the sender is kept, in quotes when it holds a character such as a comma or a dot. An inline part is referred to by its name or by its content id, set with `setContentId()` or as a `Content-ID` header, and an attachment without a name is named after its type, such as `attachment.pdf`.

Attachments are uploaded before the email is sent. The transport asks PostHog which files it does not have yet,
uploads each of them to the file storage URL that PostHog answers, without the secret key, and then sends the email
naming each file by its SHA-256. A file that PostHog already has is not uploaded again, and the same file attached
twice is uploaded once. The file storage is not on the host of the DSN, so a network that limits outgoing requests
must allow it. A failed upload sends no email.

PostHog cannot carry everything an email can hold. Instead of dropping a part of the email, the transport refuses the whole email before any request and throws a `LogicException` whose message starts with `PostHog cannot send this email:` and a code:

 * `multiple_to`: no "To" address, or more than one
 * `unsupported_address`: an address whose domain has no ASCII form in IDNA, such as one that mixes right-to-left and left-to-right letters
 * `unsupported_header`: a header other than the ones the transport reads, such as `X-Campaign` or `Content-Language`; more than one "From" address; a second `X-Track` or `X-PostHog-*` header; an `X-Track` header that does not set `opens` and `clicks` to `true`, `false` or `default`; or an `X-PostHog-Sensitive` or `X-PostHog-Capture-Engagement` header that is not `true` or `false`
 * `tags`, `metadata`: a `TagHeader` or a `MetadataHeader`
 * `envelope_mismatch`: an envelope whose sender is not the "From" address, or whose recipients are not the "To", "Cc" and "Bcc" addresses; or a `Sender` or `Return-Path` header other than the "From" address, as the API sends from that address
 * `unsupported_part`: a body set with `setBody()` that does not hold exactly the text, the HTML and the attachments of the email, such as one with an AMP part, with another text or with the text shown as an attachment; a part with a header of its own, such as a `Content-Type` parameter; an inline part that the HTML refers to both by its name and by its content id; or a body in a charset that cannot be converted to UTF-8
 * `unsupported_priority`: a priority other than normal
 * `inline_without_cid`: an inline part that has no content id and that the HTML does not refer to by its name
 * `invalid_template_key`: an `X-PostHog-Template-Key` header that does not match `[a-zA-Z0-9_-]{1,256}`
 * `template_with_content`: a remote template with a subject, copies, reply-to addresses or attachments

PostHog options
---------------

Each email can set these headers. The transport reads them and does not send them as headers:

 * `X-PostHog-Distinct-Id`: the person the send is attributed to; PostHog uses the "To" address when it is not set
 * `X-PostHog-Template-Key`: the key PostHog groups the sends by in its reports; it defaults to `symfony-mailer`
 * `X-PostHog-Idempotency-Key`: sent as the `Idempotency-Key` header of the request
 * `X-PostHog-Capture-Engagement`: `true` or `false`, to capture the engagement events of the email (sent, delivered, opened, clicked, bounced) as events of the person; it defaults to `true`
 * `X-PostHog-Sensitive`: `true` for an email that carries login links, reset tokens or codes; PostHog then keeps its links as they are and keeps its content out of logs; `false` for any other; without it, PostHog uses the setting of the template that matches the template key, and treats the email as sensitive when no template matches

A `TrackingHeader`, or the `mailer.tracking` option, turns the open pixel and the click tracking of PostHog on or off. A flag that is not set keeps the default of PostHog: opens are tracked, and clicks are tracked unless the email is sensitive. PostHog tracks clicks only together with opens: an email that turns clicks on and opens off is sent as asked, and the API refuses it with the `clicks_need_opens` code (see below).

```php
$email = (new Email())
    ->from('shop@example.com')
    ->to('ana@example.com')
    ->subject('Your order shipped')
    ->html('<p>On its way.</p>');

$email->getHeaders()
    ->addTextHeader('X-PostHog-Distinct-Id', $user->getId())
    ->addTextHeader('X-PostHog-Template-Key', 'order-shipped')
    ->addTextHeader('X-PostHog-Idempotency-Key', 'order-'.$order->getId().'-shipped');
```

To give every email the same template key, set it as a default header in the mailer configuration, for example `mailer.headers: { 'X-PostHog-Template-Key': 'transactional' }`. A header set on the email wins.

Templates
---------

The transport sends a `RemoteTemplateEmail` with the UUID of a PostHog email template and its variables:

```php
$email = (new RemoteTemplateEmail())
    ->from('shop@example.com')
    ->to('ana@example.com')
    ->template('0190f0c2-7d1e-7a3b-9c2e-5f1a2b3c4d5e', ['order' => '981']);
```

The template sets the sender, the subject and the bodies. The "From" address is still needed by Symfony to build the envelope, but it is not sent. The `X-PostHog-Template-Key`, `X-PostHog-Capture-Engagement` and `X-PostHog-Sensitive` headers and the `TrackingHeader` are checked as on any email, but they are not sent, because a template send does not take them: the template has its own settings.

Errors and retries
------------------

A failed send, or a failed upload of an attachment, throws an `HttpTransportException`. Its message holds the error detail of the API, its code and the field it is about, or the answer of the file storage. Its response holds the status code and, for a throttled send, the `Retry-After` header. The transport does not retry by itself.

These failures can be retried with the same `X-PostHog-Idempotency-Key`:

 * `409`: a send with the same key is still in progress
 * `429`, except with the `quota_limited` code: the send was throttled; wait for the `Retry-After` seconds
 * `503`, and a network error: the email may have been sent anyway

Other failures, such as a `400` with the `clicks_need_opens` code, will fail again. When Messenger sends your emails, set `X-PostHog-Idempotency-Key` from a stable value, such as the order id and the name of the email. A retry with another key, or with none, can send the email twice.

Resources
---------

 * [Contributing](https://symfony.com/doc/current/contributing/index.html)
 * [Report issues](https://github.com/symfony/symfony/issues) and
   [send Pull Requests](https://github.com/symfony/symfony/pulls)
   in the [main Symfony repository](https://github.com/symfony/symfony)
