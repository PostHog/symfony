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

The API transport carries one "To" address, the "Cc", "Bcc" and "Reply-To" addresses, the sender, the subject, the text and HTML bodies, attachments and inline images (referenced by their `cid`). The API takes bare addresses for recipients, so their display names are not sent; the display name of the sender is kept, in quotes when it holds a special character such as a comma.

PostHog cannot carry everything an email can hold. Instead of dropping a part of the email, the transport refuses the whole email before any request and throws a `LogicException` whose message starts with `PostHog cannot send this email:` and a code:

 * `multiple_to`: no "To" address, or more than one
 * `unsupported_header`: a header other than the ones the transport reads, such as `X-Campaign`, `Sender` or `Return-Path`, or more than one "From" address
 * `tags`, `metadata`: a `TagHeader` or a `MetadataHeader`
 * `envelope_mismatch`: an envelope whose sender is not the "From" address, or whose recipients are not the "To", "Cc" and "Bcc" addresses
 * `mixed_tracking`: a `TrackingHeader` with different values for opens and clicks
 * `unsupported_priority`: a priority other than normal
 * `inline_without_cid`: an inline part that has no content id and that the HTML does not refer to by its name
 * `invalid_template_key`: an `X-PostHog-Template-Key` header that does not match `[a-zA-Z0-9_-]{1,400}`
 * `template_with_content`: a remote template with a subject, copies, reply-to addresses or attachments

PostHog options
---------------

Each email can set these headers. The transport reads them and does not send them as headers:

 * `X-PostHog-Distinct-Id`: the person the send is attributed to; PostHog uses the "To" address when it is not set
 * `X-PostHog-Template-Key`: the key PostHog groups the sends by in its reports; it defaults to `symfony-mailer`
 * `X-PostHog-Idempotency-Key`: sent as the `Idempotency-Key` header of the request
 * `X-PostHog-Capture-Engagement`: `true` or `false`, to capture opens and clicks; a `TrackingHeader` with the same value for opens and clicks does the same

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

The template sets the sender, the subject and the bodies. The "From" address is still needed by Symfony to build the envelope, but it is not sent. The `X-PostHog-Template-Key` and `X-PostHog-Capture-Engagement` headers are checked as on any email, but they are not sent, because a template send does not take them.

Errors and retries
------------------

A failed send throws an `HttpTransportException`. Its message holds the error detail of the API, its code and the field it is about. Its response holds the status code and, for a throttled send, the `Retry-After` header. The transport does not retry by itself.

These failures can be retried with the same `X-PostHog-Idempotency-Key`:

 * `409`: a send with the same key is still in progress
 * `429`, except with the `quota_limited` code: the send was throttled; wait for the `Retry-After` seconds
 * `503`, and a network error: the email may have been sent anyway

Other failures will fail again. When Messenger sends your emails, set `X-PostHog-Idempotency-Key` from a stable value, such as the order id and the name of the email. A retry with another key, or with none, can send the email twice.

Resources
---------

 * [Contributing](https://symfony.com/doc/current/contributing/index.html)
 * [Report issues](https://github.com/symfony/symfony/issues) and
   [send Pull Requests](https://github.com/symfony/symfony/pulls)
   in the [main Symfony repository](https://github.com/symfony/symfony)
