<?php
/**
 * Consent resolution for server-side WooCommerce events (WP Consent API + cookie fallback).
 *
 * @package PixelFlow
 */

// Prevent direct access
if ( ! defined('ABSPATH')) {
    exit;
}

/** First-party consent-state cookie name (matches script CONSENT_COOKIE_NAME). */
const PIXELFLOW_CONSENT_COOKIE_NAME = '_pf_consent';

/** Session hold cookie the script writes while an opt-in banner is unanswered. */
const PIXELFLOW_NO_CONSENT_DECISION_COOKIE_NAME = '_pf_no_consent_decision';

/** Session cookie: detected CMP/GCM/API source while no grant/deny exists yet. */
const PIXELFLOW_CONSENT_SOURCE_COOKIE_NAME = '_pf_consent_source';

/** Literal value that means "do not send server events yet". Any other value is ignored. */
const PIXELFLOW_NO_CONSENT_DECISION_COOKIE_VALUE = 'true';

/** Consent sources accepted by the PixelFlow API ingest contract. */
const PIXELFLOW_CONSENT_SOURCES = [
    'gcm',
    'cookieyes',
    'cookiebot',
    'complianz',
    'squarespace',
    'onetrust',
    'api',
    'gpc',
    'cache',
    'consent_mode_disabled',
];

/**
 * Registers PixelFlow as a WP Consent API consumer on plugins_loaded.
 *
 * @return void
 */
function pixelflow_register_wp_consent_api_consumer(): void
{
    $plugin = PIXELFLOW_PLUGIN_BASENAME;
    add_filter("wp_consent_api_registered_{$plugin}", '__return_true');
}

/**
 * Declares the consent-state cookie to the WP Consent API cookie register.
 *
 * Runs on init, not on plugins_loaded: the strings below are translated, and
 * WordPress 6.7+ reports translation loading before init as _doing_it_wrong.
 *
 * @return void
 */
function pixelflow_register_consent_cookie_info(): void
{
    if (function_exists('wp_add_cookie_info')) {
        wp_add_cookie_info(
            PIXELFLOW_CONSENT_COOKIE_NAME,
            'PixelFlow',
            'marketing',
            __('183 days', 'pixelflow'),
            __('Stores the visitor consent decision for PixelFlow tracking (no visitor identifiers).', 'pixelflow'),
            '',
            false,
            false,
            'HTTP'
        );
        wp_add_cookie_info(
            PIXELFLOW_NO_CONSENT_DECISION_COOKIE_NAME,
            'PixelFlow',
            'functional',
            __('Session', 'pixelflow'),
            __('Coordinates a hold on server-side events while a consent banner is unanswered (no visitor identifiers).', 'pixelflow'),
            '',
            false,
            false,
            'HTTP'
        );
        wp_add_cookie_info(
            PIXELFLOW_CONSENT_SOURCE_COOKIE_NAME,
            'PixelFlow',
            'functional',
            __('Session', 'pixelflow'),
            __('Names the consent banner PixelFlow detected while no accept/decline exists yet (source only, no visitor identifiers).', 'pixelflow'),
            '',
            false,
            false,
            'HTTP'
        );
        if (defined('PIXELFLOW_HELD_WOO_EVENTS_COOKIE_NAME')) {
            wp_add_cookie_info(
                PIXELFLOW_HELD_WOO_EVENTS_COOKIE_NAME,
                'PixelFlow',
                'functional',
                __('Session', 'pixelflow'),
                __('Lists WooCommerce event types waiting for a consent decision (event names only, no visitor identifiers).', 'pixelflow'),
                '',
                false,
                false,
                'HTTP'
            );
        }
    }
}

/**
 * Returns the visitor's consent decision from the live cookie on this request.
 *
 * Its presence is what tells a server-side event that the visitor is actually
 * here: background requests (cron, order-status changes, gateway callbacks)
 * carry no cookies at all.
 *
 * @return array{state: string, source: string, timestamp: int}|null
 */
function pixelflow_get_live_consent_cookie_decision(): ?array
{
    if ( ! isset($_COOKIE[PIXELFLOW_CONSENT_COOKIE_NAME]) || ! is_string($_COOKIE[PIXELFLOW_CONSENT_COOKIE_NAME])) {
        return null;
    }

    $raw = wp_unslash($_COOKIE[PIXELFLOW_CONSENT_COOKIE_NAME]); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON decoded and field-sanitized in pixelflow_decode_consent_cookie()

    return pixelflow_decode_consent_cookie($raw);
}

/**
 * Whether a CMP has registered a consent type via the WP Consent API.
 *
 * @return bool
 */
function pixelflow_is_wp_consent_api_active(): bool
{
    return function_exists('wp_get_consent_type') && wp_get_consent_type() !== '';
}

/**
 * Maps WP Consent API marketing category to the ingest consent block.
 *
 * @return array{state: string, source: string, timestamp: int}|null
 */
function pixelflow_get_consent_from_wp_api(): ?array
{
    if ( ! function_exists('wp_has_consent') || ! pixelflow_is_wp_consent_api_active()) {
        return null;
    }

    // wp_has_consent() answers from the current request's cookies and has no
    // "unknown" return: with an opt-in consent type and no visitor present it
    // reports false, which is absence of data rather than a refusal. Only speak
    // for a visitor whose own decision is on this request.
    $live = pixelflow_get_live_consent_cookie_decision();
    if ($live === null) {
        return null;
    }

    $granted = wp_has_consent('marketing');

    return [
        'state'     => $granted ? 'granted' : 'denied',
        'source'    => 'api',
        'timestamp' => $live['timestamp'],
    ];
}

/**
 * Validates a consent source string against the API ingest enum.
 *
 * @param string $source Wire source value
 * @return bool
 */
function pixelflow_is_valid_consent_source(string $source): bool
{
    return in_array($source, PIXELFLOW_CONSENT_SOURCES, true);
}

/**
 * Decodes a consent-state cookie value; rejects payloads carrying visitor identifiers.
 *
 * @param string $value Raw cookie value (base64 JSON)
 * @return array{state: string, source: string, timestamp: int}|null
 */
function pixelflow_decode_consent_cookie(string $value): ?array
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $decoded_json = base64_decode($value, true);
    if ($decoded_json === false) {
        return null;
    }

    $parsed = json_decode($decoded_json, true);
    if ( ! is_array($parsed)) {
        return null;
    }

    $visitor_id_keys = ['visitorId', 'visitor_id', 'uid', 'userId', 'user_id'];
    foreach ($visitor_id_keys as $key) {
        if (array_key_exists($key, $parsed)) {
            return null;
        }
    }

    $state           = $parsed['s'] ?? $parsed['state'] ?? null;
    $timestamp       = $parsed['t'] ?? $parsed['timestamp'] ?? null;
    $source          = $parsed['src'] ?? $parsed['source'] ?? null;
    $policy_version  = $parsed['v'] ?? $parsed['policyVersion'] ?? null;

    if ($state !== 'granted' && $state !== 'denied') {
        return null;
    }
    if ( ! is_numeric($timestamp) || ! is_finite((float) $timestamp)) {
        return null;
    }
    if ( ! is_string($source) || $source === '' || ! pixelflow_is_valid_consent_source($source)) {
        return null;
    }
    if ( ! is_numeric($policy_version) || ! is_finite((float) $policy_version)) {
        return null;
    }

    return [
        'state'     => $state,
        'source'    => $source,
        'timestamp' => (int) $timestamp,
    ];
}

/**
 * Live marketing decision on this request (WP Consent API, then the consent cookie).
 *
 * @return array{state: string, source: string, timestamp: int}|null
 */
function pixelflow_live_consent_decision(): ?array
{
    $from_wp = pixelflow_get_consent_from_wp_api();
    if ($from_wp !== null) {
        return $from_wp;
    }

    return pixelflow_get_live_consent_cookie_decision();
}

/**
 * Resolves consent for a server-side event: live request first, then order-meta override.
 *
 * A thank-you grant must beat a hold/deny snapshot saved at checkout. Background
 * hooks (webhooks) have no live cookies and still use the override.
 *
 * @param string|null $cookie_raw_override Saved cookie from order meta for async purchase hooks
 * @return array{state: string, source: string, timestamp: int}|null
 */
function pixelflow_resolve_event_consent_block(?string $cookie_raw_override = null): ?array
{
    $live = pixelflow_live_consent_decision();
    if ($live !== null) {
        return $live;
    }

    if ($cookie_raw_override !== null && $cookie_raw_override !== '') {
        return pixelflow_decode_consent_cookie($cookie_raw_override);
    }

    return null;
}

/**
 * Reads the session CMP-source cookie (live or persisted on the order).
 *
 * @param string|null $raw_override Saved `_pf_consent_source` from order meta
 * @return string|null Allow-listed source, or null when absent
 */
function pixelflow_get_consent_source_from_cookie(?string $raw_override = null): ?string
{
    $raw = null;
    if ($raw_override !== null && $raw_override !== '') {
        $raw = $raw_override;
    } elseif (isset($_COOKIE[PIXELFLOW_CONSENT_SOURCE_COOKIE_NAME]) && is_string($_COOKIE[PIXELFLOW_CONSENT_SOURCE_COOKIE_NAME])) {
        $raw = wp_unslash($_COOKIE[PIXELFLOW_CONSENT_SOURCE_COOKIE_NAME]);
    }

    if ( ! is_string($raw)) {
        return null;
    }

    $source = sanitize_text_field($raw);
    if ($source === '' || ! pixelflow_is_valid_consent_source($source)) {
        return null;
    }

    return $source;
}

/**
 * Unknown consent block when a CMP was detected but no grant/deny exists yet.
 *
 * @param string|null $source_raw_override Saved `_pf_consent_source` from order meta
 * @return array{state: string, source: string}|null
 */
function pixelflow_unknown_consent_block_from_source(?string $source_raw_override = null): ?array
{
    $source = pixelflow_get_consent_source_from_cookie($source_raw_override);
    if ($source === null) {
        return null;
    }

    return [
        'state'  => 'unknown',
        'source' => $source,
    ];
}

/**
 * Appends the optional consent block to an event payload when a decision is knowable,
 * or when a detected CMP source is present without a grant/deny yet.
 *
 * @param array       $payload              Event payload passed by reference
 * @param string|null $cookie_raw_override  Saved consent cookie from order meta
 * @param string|null $source_raw_override  Saved `_pf_consent_source` from order meta
 * @return void
 */
function pixelflow_append_consent_to_payload(array &$payload, ?string $cookie_raw_override = null, ?string $source_raw_override = null): void
{
    if ( ! isset($payload['eventData']) || ! is_array($payload['eventData'])) {
        return;
    }

    $consent = pixelflow_resolve_event_consent_block($cookie_raw_override);
    if ($consent === null) {
        $consent = pixelflow_unknown_consent_block_from_source($source_raw_override);
    }
    if ($consent === null) {
        return;
    }

    $payload['eventData']['consent'] = $consent;
}

/**
 * Whether the hold cookie (live or persisted on the order) is the literal true value.
 * A live grant on this request wins so thank-you can send after a held checkout.
 *
 * @param string|null $raw_override Saved `_pf_no_consent_decision` from order meta
 * @return bool
 */
function pixelflow_has_no_consent_decision_hold(?string $raw_override = null): bool
{
    $live = pixelflow_live_consent_decision();
    if ($live !== null && ($live['state'] ?? '') === 'granted') {
        return false;
    }

    $raw = null;
    if ($raw_override !== null && $raw_override !== '') {
        $raw = $raw_override;
    } elseif (isset($_COOKIE[PIXELFLOW_NO_CONSENT_DECISION_COOKIE_NAME]) && is_string($_COOKIE[PIXELFLOW_NO_CONSENT_DECISION_COOKIE_NAME])) {
        $raw = wp_unslash($_COOKIE[PIXELFLOW_NO_CONSENT_DECISION_COOKIE_NAME]);
    }

    if ( ! is_string($raw)) {
        return false;
    }

    return $raw === PIXELFLOW_NO_CONSENT_DECISION_COOKIE_VALUE;
}

/**
 * Whether a WooCommerce server event may be POSTed given the hold cookie and consent decision.
 *
 * @param string|null $consent_cookie_raw Saved `_pf_consent` from order meta for async purchase hooks
 * @param string|null $no_decision_raw    Saved `_pf_no_consent_decision` from order meta
 * @return bool True to send; false when holding for a decision or consent is denied
 */
function pixelflow_should_send_event_for_consent(?string $consent_cookie_raw = null, ?string $no_decision_raw = null): bool
{
    if (pixelflow_has_no_consent_decision_hold($no_decision_raw)) {
        return false;
    }

    $consent = pixelflow_resolve_event_consent_block($consent_cookie_raw);
    if ($consent !== null && $consent['state'] === 'denied') {
        return false;
    }

    return true;
}
