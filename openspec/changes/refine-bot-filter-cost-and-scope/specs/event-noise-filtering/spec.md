## MODIFIED Requirements

### Requirement: Repeated events are suppressed without a commerce session

The plugin SHALL suppress a repeated event within its de-duplication window even when no WooCommerce session is available for the request. When a session is absent, the plugin MUST fall back to storage that persists across requests, keyed on the event identity together with the requesting client's identity, so that a request repeated by a prefetcher, a crawler, or a page reload does not produce a second event.

Client identity for this purpose MUST be derived from the client IP address and user agent, and MUST be stored in hashed form so that no raw personal data is persisted by the de-duplication mechanism.

When a WooCommerce session is available, the existing session-based de-duplication remains authoritative and the fallback MUST NOT be consulted.

The plugin MUST NOT write a persistent de-duplication record for a request it has already classified as automated. Such a request produces no event regardless of the de-duplication outcome, so the record has no effect on what is sent, and writing it makes stored suppression state grow in proportion to automated traffic — the traffic the fallback exists to absorb. Automation classification MUST therefore be evaluated before the fallback writes, and MUST use the same signature set that governs whether an event is forwarded, so the two decisions cannot disagree.

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

#### Scenario: Automated request writes no fallback record
- **WHEN** a request with user agent `Python/3.14 aiohttp/3.14.1` and no WooCommerce session triggers an AddToCart event with a de-duplication window of 5 seconds
- **THEN** no persistent de-duplication record is written
- **AND** the event is not sent to the Pixelflow API

#### Scenario: Crawler sweeping many distinct products accumulates no state
- **WHEN** an automated client requests fifty different `?add-to-cart=<id>` URLs with no WooCommerce session
- **THEN** no persistent de-duplication records exist afterwards
- **AND** no event is sent for any of the fifty requests

#### Scenario: Genuine session-less client is still de-duplicated
- **WHEN** a request whose user agent matches no automation signature triggers the same AddToCart event twice within the window with no WooCommerce session
- **THEN** the first event is sent, the second is suppressed, and a persistent record was written

## ADDED Requirements

### Requirement: A bot-suppressed event is attributable to the signature that suppressed it

When the plugin refuses to forward an event because the client user agent matched an automation signature, the debug log entry for that event SHALL identify the signature that matched, not merely that some signature did. Without it a site owner whose own client is being filtered — a native mobile application or a server-rendered storefront issuing requests under a generic HTTP-library user agent — sees the same log output as a correctly filtered crawler, and has no way to tell a working filter from one that is silently discarding real conversions.

The signature reported MUST be the one that actually caused the match, so that removing it through the `pixelflow_useragent_bot_patterns` filter is sufficient to stop the suppression.

The suppression decision itself is unchanged: which events are refused MUST NOT depend on whether debug logging is enabled.

#### Scenario: Skip reason names the matched signature
- **WHEN** an event is suppressed because the user agent `okhttp/4.12.0` matched the signature `okhttp`, and debug logging is enabled
- **THEN** the debug log entry for that event identifies `okhttp` as the matched signature

#### Scenario: Reported signature is the one that can be removed
- **WHEN** a site owner removes the signature named in the debug log entry via the `pixelflow_useragent_bot_patterns` filter
- **AND** an otherwise identical request arrives
- **THEN** the event is no longer suppressed as automated

#### Scenario: First matching signature is reported when several apply
- **WHEN** a user agent matches more than one signature in the set
- **THEN** exactly one signature is reported, and it is one the user agent genuinely contains

#### Scenario: Suppression is independent of logging
- **WHEN** an event is suppressed because the user agent matched an automation signature and debug logging is disabled
- **THEN** the event is still not sent to the Pixelflow API

#### Scenario: Non-bot suppression reasons are unaffected
- **WHEN** an event is suppressed because the resolved client IP is private rather than because of a user-agent match
- **THEN** the debug log entry reports the private-IP reason and names no automation signature
