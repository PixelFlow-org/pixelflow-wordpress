<?php
/**
 * Woo session hold queue for storefront events waiting on an opt-in decision.
 *
 * @package PixelFlow
 */

// Prevent direct access
if ( ! defined('ABSPATH')) {
    exit;
}

/** WC session key for compact held-event recipes. */
const PIXELFLOW_HELD_WOO_EVENTS_SESSION_KEY = 'pf_held_woo_events';

/** Session cookie of event type names so the storefront script can flush on grant. */
const PIXELFLOW_HELD_WOO_EVENTS_COOKIE_NAME = '_pf_held_woo_events';

/** Oldest recipes are dropped past this cap so the Woo session stays small. */
const PIXELFLOW_HELD_WOO_EVENTS_CAP = 20;

/** Storefront events that can wait in the session. Purchase is never queued. */
const PIXELFLOW_HELD_WOO_EVENT_NAMES = [
    'AddToCart',
    'InitiateCheckout',
];

/** Hashed customer keys allowed on a recipe. IP and UA stay request-scoped. */
const PIXELFLOW_HELD_CUSTOMER_KEYS = [
    'em',
    'fn',
    'ln',
    'ph',
    'ct',
    'st',
    'zp',
    'country',
    'external_id',
];

/**
 * Live hold on AddToCart or InitiateCheckout waits in session; Purchase and
 * other reasons still beacon immediately.
 *
 * @param array|null $blocked    Row from pixelflow_resolve_blocked_event_reason()
 * @param string     $event_name Catalog event name
 * @return bool
 */
function pixelflow_should_queue_held_event(?array $blocked, string $event_name): bool
{
    if ($blocked === null || ($blocked['reason'] ?? '') !== 'no_decision') {
        return false;
    }

    return in_array($event_name, PIXELFLOW_HELD_WOO_EVENT_NAMES, true);
}

/**
 * Builds a compact recipe from a Woo payload. Value and hashed PII are kept;
 * identifiers and request-scoped fields are not.
 *
 * @param array $payload      Event payload about to be skipped
 * @param int   $product_id   Parent product id (AddToCart)
 * @param int   $variation_id Variation id when the line is a variation
 * @return array<string, mixed>|null
 */
function pixelflow_held_event_recipe_from_payload(array $payload, int $product_id = 0, int $variation_id = 0): ?array
{
    if ( ! isset($payload['eventData']) || ! is_array($payload['eventData'])) {
        return null;
    }

    $event_data = $payload['eventData'];
    $event_name = isset($event_data['eventName']) ? (string) $event_data['eventName'] : '';
    if ( ! in_array($event_name, PIXELFLOW_HELD_WOO_EVENT_NAMES, true)) {
        return null;
    }

    $event_id = isset($event_data['event_id']) ? trim((string) $event_data['event_id']) : '';
    if ($event_id === '') {
        return null;
    }

    $additional = isset($event_data['additionalData']) && is_array($event_data['additionalData'])
        ? $event_data['additionalData']
        : [];
    $customer = isset($event_data['customerData']) && is_array($event_data['customerData'])
        ? $event_data['customerData']
        : [];

    return [
        'eventName'    => $event_name,
        'event_id'     => $event_id,
        'eventTime'    => isset($event_data['eventTime']) ? (int) $event_data['eventTime'] : time(),
        'product_id'   => max(0, $product_id),
        'variation_id' => max(0, $variation_id),
        'qty'          => pixelflow_held_event_qty_from_additional($additional),
        'value'        => isset($additional['value']) ? (float) $additional['value'] : 0.0,
        'currency'     => pixelflow_held_event_currency_from_additional($additional),
        'customerData' => pixelflow_sanitize_held_customer_data($customer),
    ];
}

/**
 * Quantity from contents[0] or InitiateCheckout num_items.
 *
 * @param array $additional additionalData block
 * @return int
 */
function pixelflow_held_event_qty_from_additional(array $additional): int
{
    $contents = isset($additional['contents'][0]) && is_array($additional['contents'][0])
        ? $additional['contents'][0]
        : [];
    if (isset($contents['quantity'])) {
        return max(0, (int) $contents['quantity']);
    }
    if (isset($additional['num_items'])) {
        return max(0, (int) $additional['num_items']);
    }

    return 0;
}

/**
 * Three-letter currency from additionalData, or empty when unknown.
 *
 * @param array $additional additionalData block
 * @return string
 */
function pixelflow_held_event_currency_from_additional(array $additional): string
{
    $currency = isset($additional['currency']) ? strtoupper(trim((string) $additional['currency'])) : '';
    if ($currency === '' || strlen($currency) > 8) {
        return '';
    }

    return $currency;
}

/**
 * Keeps only hashed match keys so a recipe cannot grow into a PII dump.
 *
 * @param array $customer customerData from the skipped payload
 * @return array<string, string>
 */
function pixelflow_sanitize_held_customer_data(array $customer): array
{
    $clean = [];
    foreach (PIXELFLOW_HELD_CUSTOMER_KEYS as $key) {
        if ( ! isset($customer[$key]) || ! is_scalar($customer[$key])) {
            continue;
        }
        $value = sanitize_text_field((string) $customer[$key]);
        if ($value === '' || strlen($value) > 128) {
            continue;
        }
        $clean[$key] = $value;
    }

    return $clean;
}

/**
 * Overlays the held value and currency onto rebuilt additionalData.
 *
 * @param array $additional Live additionalData from product or cart
 * @param array $recipe     Stored recipe
 * @return array
 */
function pixelflow_apply_held_recipe_to_additional_data(array $additional, array $recipe): array
{
    if (isset($recipe['value'])) {
        $additional['value'] = (float) $recipe['value'];
    }
    $currency = isset($recipe['currency']) ? (string) $recipe['currency'] : '';
    if ($currency !== '') {
        $additional['currency'] = $currency;
    }

    return $additional;
}

/**
 * What to do with a non-empty hold queue given the current cookies.
 *
 * @param string|null $consent_cookie_raw Saved `_pf_consent` when resolving off-request
 * @param string|null $no_decision_raw    Saved hold cookie when resolving off-request
 * @return string keep|send|deny|abandon
 */
function pixelflow_held_events_disposition(?string $consent_cookie_raw = null, ?string $no_decision_raw = null): string
{
    if (pixelflow_has_no_consent_decision_hold($no_decision_raw)) {
        return 'keep';
    }

    $consent = pixelflow_resolve_event_consent_block($consent_cookie_raw);
    if ($consent !== null && ($consent['state'] ?? '') === 'denied') {
        return 'deny';
    }
    if ($consent !== null && ($consent['state'] ?? '') === 'granted') {
        return 'send';
    }

    return 'abandon';
}

/**
 * Appends a recipe to the Woo session and mirrors event names to a JS-readable cookie.
 *
 * @param array $recipe Compact recipe from pixelflow_held_event_recipe_from_payload()
 * @return bool True when the recipe was stored; false when no Woo session exists
 */
function pixelflow_enqueue_held_woo_event(array $recipe): bool
{
    $session = pixelflow_woo_session();
    if ($session === null) {
        return false;
    }

    $queue = pixelflow_get_held_woo_events();
    $queue[] = $recipe;
    if (count($queue) > PIXELFLOW_HELD_WOO_EVENTS_CAP) {
        $queue = array_slice($queue, -1 * PIXELFLOW_HELD_WOO_EVENTS_CAP);
    }

    $session->set(PIXELFLOW_HELD_WOO_EVENTS_SESSION_KEY, array_values($queue));
    pixelflow_sync_held_events_cookie($queue);

    return true;
}

/**
 * Recipes waiting in the Woo session.
 *
 * @return array<int, array<string, mixed>>
 */
function pixelflow_get_held_woo_events(): array
{
    $session = pixelflow_woo_session();
    if ($session === null) {
        return [];
    }

    $raw = $session->get(PIXELFLOW_HELD_WOO_EVENTS_SESSION_KEY);
    if ( ! is_array($raw)) {
        return [];
    }

    $queue = [];
    foreach ($raw as $row) {
        if (is_array($row) && isset($row['eventName'], $row['event_id'])) {
            $queue[] = $row;
        }
    }

    return $queue;
}

/**
 * Drops the session queue and the event-name cookie.
 *
 * @return void
 */
function pixelflow_clear_held_woo_events(): void
{
    $session = pixelflow_woo_session();
    if ($session !== null) {
        $session->set(PIXELFLOW_HELD_WOO_EVENTS_SESSION_KEY, []);
    }
    pixelflow_sync_held_events_cookie([]);
}

/**
 * Event type names currently queued, for anonymous blocked-event rows.
 *
 * @param array<int, array<string, mixed>> $queue Held recipes
 * @return array<int, string>
 */
function pixelflow_held_event_names(array $queue): array
{
    $names = [];
    foreach ($queue as $row) {
        $name = isset($row['eventName']) ? (string) $row['eventName'] : '';
        if ($name !== '') {
            $names[] = $name;
        }
    }

    return $names;
}

/**
 * Woo session handler when cart session is available.
 *
 * @return object|null Object with get() and set()
 */
function pixelflow_woo_session(): ?object
{
    if ( ! function_exists('WC')) {
        return null;
    }

    $wc = WC();
    if ( ! is_object($wc) || ! isset($wc->session) || ! is_object($wc->session)) {
        return null;
    }
    if ( ! method_exists($wc->session, 'get') || ! method_exists($wc->session, 'set')) {
        return null;
    }

    return $wc->session;
}

/**
 * Writes the anonymous event-name cookie the storefront script polls.
 *
 * @param array<int, array<string, mixed>> $queue Held recipes
 * @return void
 */
function pixelflow_sync_held_events_cookie(array $queue): void
{
    $names = pixelflow_held_event_names($queue);
    $value = $names === [] ? '' : (string) wp_json_encode($names);
    $path  = defined('COOKIEPATH') && COOKIEPATH !== '' ? COOKIEPATH : '/';
    $secure = function_exists('is_ssl') ? is_ssl() : false;
    $expires = $value === '' ? time() - 3600 : 0;

    if ( ! headers_sent()) {
        setcookie(
            PIXELFLOW_HELD_WOO_EVENTS_COOKIE_NAME,
            $value,
            [
                'expires'  => $expires,
                'path'     => $path,
                'secure'   => $secure,
                'httponly' => false,
                'samesite' => 'Lax',
            ]
        );
    }

    if ($value === '') {
        unset($_COOKIE[PIXELFLOW_HELD_WOO_EVENTS_COOKIE_NAME]);
        return;
    }

    $_COOKIE[PIXELFLOW_HELD_WOO_EVENTS_COOKIE_NAME] = $value;
}
