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
    }
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

    $granted = wp_has_consent('marketing');

    return [
        'state'     => $granted ? 'granted' : 'denied',
        'source'    => 'api',
        'timestamp' => (int) round(microtime(true) * 1000),
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
 * Formats a decoded cookie decision as the event ingest consent block.
 *
 * @param array{state: string, source: string, timestamp: int} $decision Decoded cookie payload
 * @return array{state: string, source: string, timestamp: int}
 */
function pixelflow_format_consent_block(array $decision): array
{
    return [
        'state'     => $decision['state'],
        'source'    => $decision['source'],
        'timestamp' => $decision['timestamp'],
    ];
}

/**
 * Resolves consent for a server-side event: WP Consent API, then cookie override, then live cookie.
 *
 * @param string|null $cookie_raw_override Saved cookie from order meta for async purchase hooks
 * @return array{state: string, source: string, timestamp: int}|null
 */
function pixelflow_resolve_event_consent_block(?string $cookie_raw_override = null): ?array
{
    $from_wp = pixelflow_get_consent_from_wp_api();
    if ($from_wp !== null) {
        return $from_wp;
    }

    if ($cookie_raw_override !== null && $cookie_raw_override !== '') {
        $from_override = pixelflow_decode_consent_cookie($cookie_raw_override);
        if ($from_override !== null) {
            return pixelflow_format_consent_block($from_override);
        }
    }

    if (isset($_COOKIE[PIXELFLOW_CONSENT_COOKIE_NAME]) && is_string($_COOKIE[PIXELFLOW_CONSENT_COOKIE_NAME])) {
        $raw = wp_unslash($_COOKIE[PIXELFLOW_CONSENT_COOKIE_NAME]); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON decoded and field-sanitized below
        if ($raw !== '') {
            $from_cookie = pixelflow_decode_consent_cookie($raw);
            if ($from_cookie !== null) {
                return pixelflow_format_consent_block($from_cookie);
            }
        }
    }

    return null;
}

/**
 * Appends the optional consent block to an event payload when a decision is knowable.
 *
 * @param array       $payload             Event payload passed by reference
 * @param string|null $cookie_raw_override Saved consent cookie from order meta
 * @return void
 */
function pixelflow_append_consent_to_payload(array &$payload, ?string $cookie_raw_override = null): void
{
    if ( ! isset($payload['eventData']) || ! is_array($payload['eventData'])) {
        return;
    }

    $consent = pixelflow_resolve_event_consent_block($cookie_raw_override);
    if ($consent === null) {
        return;
    }

    $payload['eventData']['consent'] = $consent;
}
