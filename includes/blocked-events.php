<?php
/**
 * Anonymous blocked-events beacon for WooCommerce skips (hold, deny, bot).
 *
 * @package PixelFlow
 */

// Prevent direct access
if ( ! defined('ABSPATH')) {
    exit;
}

/** Reasons the plugin may POST to /blocked-events. GPC is script-only. */
const PIXELFLOW_BLOCKED_EVENT_REASONS = [
    'denied',
    'no_decision',
    'bot',
];

/**
 * Consent sources the blocked-events ingest allow-list accepts.
 * Excludes consent_mode_disabled: a Disabled site still sends /event, never this beacon.
 */
const PIXELFLOW_BLOCKED_EVENT_CONSENT_SOURCES = [
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
 * Returns the first bot-pattern substring that matched, for anonymous telemetry detail.
 *
 * @param string $user_agent Client user agent (never stored on the row)
 * @return string|null Matched pattern, or null when the agent is not a bot
 */
function pixelflow_get_bot_detail_pattern(string $user_agent): ?string
{
    if ( ! defined('PIXELFLOW_BOT_PATTERNS')) {
        return null;
    }

    $bot_patterns = apply_filters('pixelflow_useragent_bot_patterns', PIXELFLOW_BOT_PATTERNS);
    if ( ! is_array($bot_patterns)) {
        return null;
    }

    $lower_ua = strtolower($user_agent);
    foreach ($bot_patterns as $pattern) {
        $pattern = (string) $pattern;
        if ($pattern !== '' && strpos($lower_ua, $pattern) !== false) {
            return $pattern;
        }
    }

    return null;
}

/**
 * Maps a Woo skip to the script's blocked-event reason. Bot wins so a crawler
 * with an unanswered banner is counted as bot, not no_decision.
 *
 * @param string|null $consent_cookie_raw Saved `_pf_consent` from order meta
 * @param string|null $no_decision_raw    Saved `_pf_no_consent_decision` from order meta
 * @param string|null $bot_detail         Matched bot pattern, or null when not a bot
 * @param string|null $source_cookie_raw  Saved `_pf_consent_source` from order meta
 * @return array{reason: string, detail?: string, consentSource?: string}|null
 */
function pixelflow_resolve_blocked_event_reason(?string $consent_cookie_raw = null, ?string $no_decision_raw = null, ?string $bot_detail = null, ?string $source_cookie_raw = null): ?array
{
    if ($bot_detail !== null && $bot_detail !== '') {
        return [
            'reason' => 'bot',
            'detail' => $bot_detail,
        ];
    }

    if (pixelflow_has_no_consent_decision_hold($no_decision_raw)) {
        return pixelflow_blocked_event_row_with_source('no_decision', $consent_cookie_raw, $source_cookie_raw);
    }

    $consent = pixelflow_resolve_event_consent_block($consent_cookie_raw);
    if ($consent !== null && $consent['state'] === 'denied') {
        $row = [ 'reason' => 'denied' ];
        $source = pixelflow_sanitize_blocked_consent_source(
            isset($consent['source']) && is_string($consent['source']) ? $consent['source'] : ''
        );
        if ($source !== null) {
            $row['consentSource'] = $source;
        }

        return $row;
    }

    return null;
}

/**
 * Builds a hold/no_decision row and attaches a known CMP source when one exists.
 *
 * @param string      $reason             Blocked-event reason
 * @param string|null $consent_cookie_raw Cookie used to look up an optional source
 * @param string|null $source_cookie_raw  Saved `_pf_consent_source` from order meta
 * @return array{reason: string, consentSource?: string}
 */
function pixelflow_blocked_event_row_with_source(string $reason, ?string $consent_cookie_raw, ?string $source_cookie_raw = null): array
{
    $row    = [ 'reason' => $reason ];
    $source = pixelflow_blocked_event_consent_source($consent_cookie_raw, $source_cookie_raw);
    if ($source !== null) {
        $row['consentSource'] = $source;
    }

    return $row;
}

/**
 * Consent source for a blocked row: decision cookie first, then CMP source cookie.
 *
 * @param string|null $consent_cookie_raw Saved `_pf_consent` from order meta
 * @param string|null $source_cookie_raw  Saved `_pf_consent_source` from order meta
 * @return string|null Wire source, or null when unknown or not allow-listed
 */
function pixelflow_blocked_event_consent_source(?string $consent_cookie_raw, ?string $source_cookie_raw = null): ?string
{
    $consent = pixelflow_resolve_event_consent_block($consent_cookie_raw);
    if ($consent !== null && isset($consent['source']) && is_string($consent['source'])) {
        $from_decision = pixelflow_sanitize_blocked_consent_source($consent['source']);
        if ($from_decision !== null) {
            return $from_decision;
        }
    }

    $from_source = pixelflow_get_consent_source_from_cookie($source_cookie_raw);

    return $from_source !== null ? pixelflow_sanitize_blocked_consent_source($from_source) : null;
}

/**
 * Drops sources the blocked-events ingest would 400 (notably consent_mode_disabled).
 *
 * @param string $source Candidate wire source
 * @return string|null Allow-listed source, or null to omit the field
 */
function pixelflow_sanitize_blocked_consent_source(string $source): ?string
{
    if ( ! in_array($source, PIXELFLOW_BLOCKED_EVENT_CONSENT_SOURCES, true)) {
        return null;
    }

    return $source;
}

/**
 * Builds the anonymous POST /blocked-events body. Strips fields the reason must not carry.
 *
 * @param string $site_id    External site id
 * @param string $event_type Catalog event name (AddToCart, InitiateCheckout, Purchase)
 * @param array  $row        Reason row from pixelflow_resolve_blocked_event_reason()
 * @return array{siteId: string, blocked: array<int, array<string, string>>, client_ip_address?: string}|null
 */
function pixelflow_build_blocked_events_payload(string $site_id, string $event_type, array $row): ?array
{
    $site_id    = trim($site_id);
    $event_type = trim($event_type);
    if ($site_id === '' || $event_type === '') {
        return null;
    }

    $reason = isset($row['reason']) && is_string($row['reason']) ? $row['reason'] : '';
    if ( ! in_array($reason, PIXELFLOW_BLOCKED_EVENT_REASONS, true)) {
        return null;
    }

    $entry = [
        'eventType' => $event_type,
        'reason'    => $reason,
    ];

    if ($reason === 'bot') {
        $detail = isset($row['detail']) && is_string($row['detail']) ? trim($row['detail']) : '';
        if ($detail !== '') {
            $entry['detail'] = $detail;
        }
    }

    if ($reason === 'denied' || $reason === 'no_decision') {
        $raw_source = isset($row['consentSource']) && is_string($row['consentSource']) ? $row['consentSource'] : '';
        $source     = pixelflow_sanitize_blocked_consent_source($raw_source);
        if ($source !== null) {
            $entry['consentSource'] = $source;
        }
    }

    $payload = [
        'siteId'  => $site_id,
        'blocked' => [ $entry ],
    ];

    $ip = function_exists('pixelflow_get_client_ip_address')
        ? pixelflow_get_client_ip_address()
        : '';
    if ($ip !== '' && function_exists('pixelflow_is_private_ip') && ! pixelflow_is_private_ip($ip)) {
        $payload['client_ip_address'] = $ip;
    }

    return $payload;
}

/**
 * Fire-and-forget POST of an already-built blocked-events payload.
 *
 * @param string $api_url Event API origin (no path)
 * @param string $api_key Snippet API key (same as POST /event)
 * @param array  $payload Body from pixelflow_build_blocked_events_payload()
 * @return void
 */
function pixelflow_post_blocked_events(string $api_url, string $api_key, array $payload): void
{
    $api_url = rtrim($api_url, '/');
    $api_key = trim($api_key);
    if ($api_url === '' || $api_key === '') {
        return;
    }

    if ( ! isset($payload['siteId'], $payload['blocked']) || ! is_array($payload['blocked']) || $payload['blocked'] === []) {
        return;
    }

    $timeout = (int) apply_filters('pixelflow_request_timeout', 5, $payload['siteId']);
    if ($timeout <= 0) {
        $timeout = 5;
    }

    wp_remote_post(
        $api_url . '/blocked-events',
        [
            'method'      => 'POST',
            'timeout'     => $timeout,
            'blocking'    => false,
            'sslverify'   => true,
            'redirection' => 0,
            'headers'     => [
                'Content-Type' => 'application/json',
                'api-key'      => $api_key,
            ],
            'body'        => wp_json_encode($payload),
            'data_format' => 'body',
        ]
    );
}
