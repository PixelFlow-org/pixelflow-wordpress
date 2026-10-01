<?php
/**
 * Hold queue for form events waiting on an opt-in decision.
 *
 * Independent of the WooCommerce queue in held-events.php: that one lives in `WC()->session`,
 * accepts only AddToCart and InitiateCheckout, and does not exist on a site without
 * WooCommerce. Form recipes live in a transient keyed by a random token the plugin keeps in
 * its own cookie, because the visitor id cookie does not exist until consent is given — and
 * a visitor under an unanswered banner is the only visitor who is ever held.
 *
 * @package PixelFlow
 */

// Prevent direct access
if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * First-party cookie holding the hold token. The token identifies no visitor: it only lets
 * a later request of the same browser find the recipes, as WooCommerce's session cookie does
 * for its own queue. Being non-empty, it is also the hint the flush script reads.
 */
const PIXELFLOW_HELD_FORM_EVENTS_COOKIE_NAME = '_pf_held_form_events';

/** Oldest recipes are dropped past this cap, as in the WooCommerce queue. */
const PIXELFLOW_HELD_FORM_EVENTS_CAP = 20;

/** 48 hours: the default lifetime of a guest's WooCommerce session, which the Woo queue dies with. */
const PIXELFLOW_HELD_FORM_EVENTS_TTL = 172800;

/**
 * Cap on stored recipes per browser, adjustable by the site.
 *
 * @return int
 */
function pixelflow_held_form_events_cap(): int
{
    $cap = (int) apply_filters('pixelflow_held_form_events_cap', PIXELFLOW_HELD_FORM_EVENTS_CAP);

    return $cap > 0 ? $cap : PIXELFLOW_HELD_FORM_EVENTS_CAP;
}

/**
 * The visitor id from the attribution cookie, else `_pf_uid`. Present only once the visitor
 * has consented; the repeat window uses it when it exists.
 *
 * @return string|null
 */
function pixelflow_form_visitor_id(): ?string
{
    $attribution = pixelflow_get_attribution_from_cookie();

    return is_array($attribution) && isset($attribution['visitor_id']) && $attribution['visitor_id'] !== ''
        ? (string) $attribution['visitor_id']
        : null;
}

/**
 * The hold token this request carries, or null when it carries none.
 *
 * @return string|null
 */
function pixelflow_held_form_token(): ?string
{
    $raw = isset($_COOKIE[PIXELFLOW_HELD_FORM_EVENTS_COOKIE_NAME]) && is_string($_COOKIE[PIXELFLOW_HELD_FORM_EVENTS_COOKIE_NAME])
        ? sanitize_text_field(wp_unslash($_COOKIE[PIXELFLOW_HELD_FORM_EVENTS_COOKIE_NAME]))
        : '';

    return preg_match('/^[a-f0-9]{32}$/', $raw) === 1 ? $raw : null;
}

/**
 * Whether this response can still set the hold cookie.
 *
 * @return bool
 */
function pixelflow_held_form_cookie_writable(): bool
{
    return (bool) apply_filters('pixelflow_held_form_cookie_writable', ! headers_sent());
}

/**
 * The token to hold a submission under: the one this browser already carries, else a new one
 * whose cookie is set now. Null when there is none and the cookie can no longer be set.
 *
 * @return string|null
 */
function pixelflow_held_form_token_for_hold(): ?string
{
    $token = pixelflow_held_form_token();
    if ($token !== null) {
        return $token;
    }
    if ( ! pixelflow_held_form_cookie_writable()) {
        return null;
    }

    return bin2hex(random_bytes(16));
}

/**
 * @param string $token Hold token
 * @return string Transient name
 */
function pixelflow_held_form_events_key(string $token): string
{
    return 'pf_held_forms_' . md5($token);
}

/**
 * Compact recipe of a form event about to be held: hashed customer keys, the event name, id
 * and time, the form title and the static value. Nothing request-scoped and no raw value.
 *
 * @param array $payload Assembled event payload
 * @return array|null
 */
function pixelflow_held_form_recipe_from_payload(array $payload): ?array
{
    $event_data = isset($payload['eventData']) && is_array($payload['eventData']) ? $payload['eventData'] : null;
    if ($event_data === null) {
        return null;
    }

    $event_name = isset($event_data['eventName']) ? (string) $event_data['eventName'] : '';
    $event_id   = isset($event_data['event_id']) ? trim((string) $event_data['event_id']) : '';
    if ($event_name === '' || $event_id === '') {
        return null;
    }

    $additional = isset($event_data['additionalData']) && is_array($event_data['additionalData'])
        ? $event_data['additionalData']
        : [];
    $customer   = isset($event_data['customerData']) && is_array($event_data['customerData'])
        ? $event_data['customerData']
        : [];

    $recipe = [
        'eventName'    => sanitize_text_field($event_name),
        'event_id'     => $event_id,
        'eventTime'    => isset($event_data['eventTime']) ? (int) $event_data['eventTime'] : time(),
        'contentName'  => isset($additional['contentName']) ? sanitize_text_field((string) $additional['contentName']) : '',
        'customerData' => pixelflow_sanitize_held_customer_data($customer),
    ];
    if (isset($additional['value']) && is_numeric($additional['value'])) {
        $recipe['value'] = (float) $additional['value'];
    }

    return $recipe;
}

/**
 * Recipes held under a token.
 *
 * @param string $token Hold token
 * @return array<int, array<string, mixed>>
 */
function pixelflow_get_held_form_events(string $token): array
{
    $raw = get_transient(pixelflow_held_form_events_key($token));
    if ( ! is_array($raw)) {
        return [];
    }

    // Every hold saves the queue again with a fresh TTL, so each recipe's own age is checked:
    // its eventTime is taken when it is stored.
    $cutoff = time() - PIXELFLOW_HELD_FORM_EVENTS_TTL;
    $queue  = [];
    foreach ($raw as $row) {
        if (is_array($row) && isset($row['eventName'], $row['event_id']) && (int) ($row['eventTime'] ?? 0) > $cutoff) {
            $queue[] = $row;
        }
    }

    return $queue;
}

/**
 * Appends a recipe, evicting the oldest past the cap exactly as the Woo queue does.
 *
 * @param string $token  Hold token
 * @param array  $recipe Recipe from pixelflow_held_form_recipe_from_payload()
 * @return void
 */
function pixelflow_enqueue_held_form_event(string $token, array $recipe): void
{
    $cap     = pixelflow_held_form_events_cap();
    $queue   = pixelflow_get_held_form_events($token);
    $queue[] = $recipe;
    if (count($queue) > $cap) {
        $queue = array_slice($queue, -1 * $cap);
    }

    set_transient(pixelflow_held_form_events_key($token), array_values($queue), PIXELFLOW_HELD_FORM_EVENTS_TTL);
    pixelflow_sync_held_form_events_cookie($token);
}

/**
 * Drops the recipes held under a token and the hold cookie.
 *
 * @param string $token Hold token
 * @return void
 */
function pixelflow_clear_held_form_events(string $token): void
{
    delete_transient(pixelflow_held_form_events_key($token));
    pixelflow_sync_held_form_events_cookie(null);
}

/**
 * Writes the hold cookie with its token, or removes it.
 *
 * @param string|null $token Hold token, or null to remove the cookie
 * @return void
 */
function pixelflow_sync_held_form_events_cookie(?string $token): void
{
    $has_queue = $token !== null;
    $path   = defined('COOKIEPATH') && COOKIEPATH !== '' ? COOKIEPATH : '/';
    $secure = function_exists('is_ssl') ? is_ssl() : false;

    if ( ! headers_sent()) {
        setcookie(
            PIXELFLOW_HELD_FORM_EVENTS_COOKIE_NAME,
            $has_queue ? $token : '',
            [
                'expires'  => $has_queue ? time() + PIXELFLOW_HELD_FORM_EVENTS_TTL : time() - 3600,
                'path'     => $path,
                'secure'   => $secure,
                'httponly' => false,
                'samesite' => 'Lax',
            ]
        );
    }

    if ($has_queue) {
        $_COOKIE[PIXELFLOW_HELD_FORM_EVENTS_COOKIE_NAME] = $token;
    } else {
        unset($_COOKIE[PIXELFLOW_HELD_FORM_EVENTS_COOKIE_NAME]);
    }
}
