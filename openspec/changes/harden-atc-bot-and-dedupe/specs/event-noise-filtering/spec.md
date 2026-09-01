## Purpose

Defines which tracking events the plugin refuses to forward to the Pixelflow API, so that automated traffic and mechanically repeated requests do not inflate the conversion volume reported to Meta.

## ADDED Requirements

### Requirement: Automation user agents are recognised as bots

The plugin SHALL classify a request as automated when its client user agent matches a known automation signature, and SHALL NOT forward any event originating from such a request. The signature set MUST cover generic HTTP client libraries used by scripts and scrapers, not only agents whose name contains the literal string "bot".

The signature set MUST include, at minimum, matches for: `aiohttp`, `httpx`, `okhttp`, `go-http-client`, `java/`, `libwww-perl`, `guzzle`, and `node-fetch`, in addition to the previously covered signatures.

Matching MUST remain case-insensitive and MUST remain a substring match, so that version suffixes and surrounding tokens in the user-agent string do not defeat it.

#### Scenario: Python asynchronous HTTP client
- **WHEN** a request arrives with user agent `Python/3.14 aiohttp/3.14.1` and triggers an AddToCart event
- **THEN** the event is not sent to the Pixelflow API
- **AND** the debug log records the event as skipped with reason `BOT_UA`

#### Scenario: Python httpx client
- **WHEN** a request arrives with user agent `python-httpx/0.28.1` and triggers an AddToCart event
- **THEN** the event is not sent to the Pixelflow API

#### Scenario: Genuine browser is unaffected
- **WHEN** a request arrives with user agent `Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36` and triggers an AddToCart event
- **THEN** the event is sent to the Pixelflow API

#### Scenario: Signature set remains extensible by site owners
- **WHEN** a site adds a signature via the `pixelflow_useragent_bot_patterns` filter
- **THEN** requests whose user agent contains that signature are treated as automated

### Requirement: Meta crawler variants are recognised as bots

The plugin SHALL treat every user agent in Meta's crawler family as automated, including agents the previous signature set did not match. Matching MUST be by the shared `meta-external` prefix rather than by an exhaustive list of full agent names, so that future Meta crawler variants are covered without a further code change.

#### Scenario: Meta ads crawler
- **WHEN** a request arrives with user agent `meta-externalads/1.1` and triggers an AddToCart event
- **THEN** the event is not sent to the Pixelflow API

#### Scenario: Previously covered Meta agent still matches
- **WHEN** a request arrives with user agent `meta-externalagent/1.1`
- **THEN** the request is classified as automated

#### Scenario: Unrelated agent containing "meta" does not match
- **WHEN** a request arrives with user agent `Mozilla/5.0 MetaBrowser/2.0`
- **THEN** the request is not classified as automated on the basis of the Meta signature

### Requirement: Repeated events are suppressed without a commerce session

The plugin SHALL suppress a repeated event within its de-duplication window even when no WooCommerce session is available for the request. When a session is absent, the plugin MUST fall back to storage that persists across requests, keyed on the event identity together with the requesting client's identity, so that a request repeated by a prefetcher, a crawler, or a page reload does not produce a second event.

Client identity for this purpose MUST be derived from the client IP address and user agent, and MUST be stored in hashed form so that no raw personal data is persisted by the de-duplication mechanism.

When a WooCommerce session is available, the existing session-based de-duplication remains authoritative and the fallback MUST NOT be consulted.

#### Scenario: Session-less repeat within the window
- **WHEN** two requests with identical client IP and user agent, and no WooCommerce session, trigger the same AddToCart event 1 second apart with a de-duplication window of 5 seconds
- **THEN** the first event is sent and the second is suppressed

#### Scenario: Session-less repeat after the window
- **WHEN** the same two requests occur 6 seconds apart with a de-duplication window of 5 seconds
- **THEN** both events are sent

#### Scenario: Different clients are not conflated
- **WHEN** two requests trigger the same event within the window but originate from different client IP addresses
- **THEN** both events are sent

#### Scenario: Zero-length window disables suppression
- **WHEN** an event is de-duplicated with a window of 0 seconds and a prior record exists from an earlier request
- **THEN** the event is sent, matching the existing behaviour of the session-based path

#### Scenario: Session-based path is unchanged
- **WHEN** a WooCommerce session is available for the request
- **THEN** de-duplication uses the session record and no persistent fallback record is written

#### Scenario: Suppression state does not accumulate indefinitely
- **WHEN** a fallback de-duplication record is written
- **THEN** it expires automatically no later than a bounded interval after the de-duplication window elapses
