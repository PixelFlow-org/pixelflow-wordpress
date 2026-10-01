<?php
/**
 * The form hold queue: a submission made while an opt-in banner is unanswered is held as a
 * recipe of hashes and sent, reported or dropped once the visitor decides. Runs with no WC().
 *
 * Run: php tests/test-form-hold.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-forms.php';

$failures = [];
$passes   = 0;

function wp_create_nonce($action = -1)
{
    return 'nonce';
}

function wp_send_json_success($data = null)
{
    $GLOBALS['__pf_json'] = ['success' => true, 'data' => $data];
}

/** Recipes held under this browser's hold token. */
function pf_held(): array
{
    return pf_held_recipes();
}

/** Holds one contact submission. */
function pf_hold_one(array $overrides = []): string
{
    pf_banner_unanswered();

    return PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission($overrides));
}

pf_forms_case('A hold stores hashed keys only and sends no event', function () {
    $outcome = pf_hold_one();
    $queue   = pf_held();

    if ($outcome !== 'held' || count($queue) !== 1) {
        return "outcome {$outcome}, recipes " . count($queue);
    }
    if (pf_sent_events() !== [] || pf_blocked_rows() !== []) {
        return 'something was POSTed while holding';
    }
    $raw = (string) json_encode($GLOBALS['__pf_transients']);
    if (stripos($raw, 'ada') !== false || strpos($raw, 'SECRET-MESSAGE') !== false || strpos($raw, '5678') !== false) {
        return 'a raw value was stored: ' . $raw;
    }
    $keys = array_keys($queue[0]['customerData']);
    sort($keys);

    if ($keys !== ['em', 'fn', 'ln', 'ph']) {
        return 'stored keys ' . implode(',', $keys);
    }
    $token = (string) ($_COOKIE[PIXELFLOW_HELD_FORM_EVENTS_COOKIE_NAME] ?? '');

    return preg_match('/^[a-f0-9]{32}$/', $token) === 1 ? true : 'the hold cookie carries no random token: ' . $token;
}, $failures, $passes);

pf_forms_case('A grant sends once with the original eventTime and the hashes taken at hold time', function () {
    pf_hold_one();
    $recipe = pf_held()[0];
    $GLOBALS['__pf_now'] += 600;
    pf_decide('granted');
    PixelFlow_Form_Dispatcher::resolve_held();
    PixelFlow_Form_Dispatcher::resolve_held();

    $event = pf_only_event();
    if ($event === null) {
        return 'expected exactly one event, got ' . count(pf_sent_events());
    }
    if ($event['eventTime'] !== $recipe['eventTime'] || $event['event_id'] !== $recipe['event_id']) {
        return 'eventTime or event_id changed on replay';
    }

    return ($event['customerData']['em'] ?? null) === $recipe['customerData']['em'] && pf_held() === []
        ? true
        : 'hashes differ or the recipe survived';
}, $failures, $passes);

pf_forms_case('A held static value is sent after the grant with no currency', function () {
    update_option(PIXELFLOW_FORM_SETTINGS_OPTION, pixelflow_apply_form_settings_patch([], ['custom:7' => ['value' => 40]]));
    pf_hold_one();
    pf_decide('granted');
    PixelFlow_Form_Dispatcher::resolve_held();
    $event = pf_only_event();

    if ( ! isset($event['additionalData']['value']) || (float) $event['additionalData']['value'] !== 40.0) {
        return 'value was ' . json_encode($event['additionalData'] ?? null);
    }

    return array_key_exists('currency', $event['additionalData']) ? 'a currency was sent' : true;
}, $failures, $passes);

pf_forms_case('A grant takes fbp and ttp from the sending request', function () {
    pf_hold_one();
    pf_decide('granted');
    $_COOKIE['_fbp'] = 'fb.1.1700000000.999';
    $_COOKIE['_ttp'] = 'tiktok-after-grant';
    PixelFlow_Form_Dispatcher::resolve_held();
    $event = pf_only_event();

    return ($event['fbp'] ?? null) === 'fb.1.1700000000.999' && ($event['ttp'] ?? null) === 'tiktok-after-grant'
        ? true
        : 'fbp/ttp were ' . json_encode([$event['fbp'] ?? null, $event['ttp'] ?? null]);
}, $failures, $passes);

pf_forms_case('Two submissions of one form within two minutes while held store one recipe and send one event', function () {
    pf_hold_one();
    PixelFlow_Form_Dispatcher::reset_request_state();
    $second = pf_hold_one();
    if ($second !== 'skipped' || count(pf_held()) !== 1) {
        return "second outcome {$second}, recipes " . count(pf_held());
    }
    pf_decide('granted');
    PixelFlow_Form_Dispatcher::resolve_held();

    return count(pf_sent_events()) === 1 ? true : 'events ' . count(pf_sent_events());
}, $failures, $passes);

pf_forms_case('A denial reports denied and leaves no recipe', function () {
    pf_hold_one();
    pf_decide('denied');
    PixelFlow_Form_Dispatcher::resolve_held();
    $rows = pf_blocked_rows();

    if (pf_sent_events() !== [] || pf_held() !== []) {
        return 'an event was sent or the recipe survived';
    }

    return count($rows) === 1 && $rows[0]['reason'] === 'denied' && $rows[0]['eventType'] === 'Lead'
        ? true
        : 'rows ' . json_encode($rows);
}, $failures, $passes);

pf_forms_case('An abandoned hold reports no_decision', function () {
    pf_hold_one();
    unset($_COOKIE['_pf_no_consent_decision']);
    PixelFlow_Form_Dispatcher::resolve_held();
    $rows = pf_blocked_rows();

    return count($rows) === 1 && $rows[0]['reason'] === 'no_decision' && pf_held() === []
        ? true
        : 'rows ' . json_encode($rows);
}, $failures, $passes);

pf_forms_case('An unanswered banner keeps the recipe', function () {
    pf_hold_one();
    PixelFlow_Form_Dispatcher::resolve_held();

    return count(pf_held()) === 1 && $GLOBALS['__pf_http'] === [] ? true : 'the recipe was resolved early';
}, $failures, $passes);

pf_forms_case('A visitor with no visitor cookie is held and sent after a grant', function () {
    $outcome = pf_hold_one();
    if ($outcome !== 'held' || isset($_COOKIE['_pf_uid'])) {
        return "outcome {$outcome}";
    }
    pf_decide('granted');
    PixelFlow_Form_Dispatcher::resolve_held();

    return count(pf_sent_events()) === 1 && pf_blocked_rows() === [] ? true : 'events ' . count(pf_sent_events());
}, $failures, $passes);

pf_forms_case('A visitor who later has a visitor cookie still finds the recipe by the hold token', function () {
    pf_hold_one();
    pf_with_visitor_cookie();
    pf_decide('granted');
    PixelFlow_Form_Dispatcher::resolve_held();

    return count(pf_sent_events()) === 1 ? true : 'events ' . count(pf_sent_events());
}, $failures, $passes);

pf_forms_case('A response that can no longer set the hold cookie reports no_decision immediately', function () {
    $GLOBALS['__pf_test_filters']['pixelflow_held_form_cookie_writable'] = false;
    $outcome = pf_hold_one();
    $rows    = pf_blocked_rows();

    return $outcome === 'blocked' && count($rows) === 1 && $rows[0]['reason'] === 'no_decision' && pf_sent_events() === []
        ? true
        : "outcome {$outcome}, rows " . json_encode($rows);
}, $failures, $passes);

pf_forms_case('An expired recipe sends neither an event nor a blocked report', function () {
    pf_hold_one();
    $GLOBALS['__pf_now'] += PIXELFLOW_HELD_FORM_EVENTS_TTL + 1;
    pf_decide('granted');
    PixelFlow_Form_Dispatcher::resolve_held();

    return $GLOBALS['__pf_http'] === [] ? true : 'something was POSTed';
}, $failures, $passes);

pf_forms_case('A recipe expires 48 hours after it was stored even when a later hold saves the queue again', function () {
    pf_hold_one(['form_id' => '1']);
    $key = pixelflow_held_form_events_key((string) pixelflow_held_form_token());
    $GLOBALS['__pf_transients'][$key]['value'][0]['eventTime'] = time() - PIXELFLOW_HELD_FORM_EVENTS_TTL - 1;
    PixelFlow_Form_Dispatcher::reset_request_state();
    pf_hold_one(['form_id' => '2']);
    $fresh = pf_held();
    pf_decide('granted');
    PixelFlow_Form_Dispatcher::resolve_held();

    if (count($fresh) !== 1) {
        return 'recipes after the second hold ' . count($fresh);
    }
    $event = pf_only_event();

    return $event !== null && $event['event_id'] === $fresh[0]['event_id'] && pf_blocked_rows() === []
        ? true
        : 'events ' . count(pf_sent_events()) . ', rows ' . count(pf_blocked_rows());
}, $failures, $passes);

pf_forms_case('The state route removes a hint cookie whose queue is gone', function () {
    pf_hold_one();
    delete_transient(pixelflow_held_form_events_key((string) pixelflow_held_form_token()));
    pf_decide('granted');
    pixelflow_forms_ajax_held_state();

    if (($GLOBALS['__pf_json']['data']['hasQueue'] ?? null) !== false) {
        return 'answer ' . json_encode($GLOBALS['__pf_json'] ?? null);
    }

    return isset($_COOKIE[PIXELFLOW_HELD_FORM_EVENTS_COOKIE_NAME]) ? 'the hint cookie survived' : true;
}, $failures, $passes);

pf_forms_case('The state route keeps the hint cookie while a queue exists', function () {
    pf_hold_one();
    pf_decide('granted');
    pixelflow_forms_ajax_held_state();

    if (($GLOBALS['__pf_json']['data']['hasQueue'] ?? null) !== true) {
        return 'answer ' . json_encode($GLOBALS['__pf_json'] ?? null);
    }

    return isset($_COOKIE[PIXELFLOW_HELD_FORM_EVENTS_COOKIE_NAME]) ? true : 'the hint cookie was removed';
}, $failures, $passes);

pf_forms_case('A twenty-first held recipe evicts the oldest', function () {
    for ($i = 1; $i <= 21; $i++) {
        PixelFlow_Form_Dispatcher::reset_request_state();
        pf_hold_one(['form_id' => (string) $i]);
    }
    $queue = pf_held();

    if (count($queue) !== 20) {
        return 'recipes ' . count($queue);
    }

    return $GLOBALS['__pf_http'] === [] ? true : 'eviction POSTed something';
}, $failures, $passes);

pf_forms_case('Nothing is ever written to the WooCommerce queue', function () {
    pf_hold_one();

    if (pixelflow_get_held_woo_events() !== []) {
        return 'the Woo queue has entries';
    }
    if (isset($_COOKIE[PIXELFLOW_HELD_WOO_EVENTS_COOKIE_NAME])) {
        return 'the Woo hint cookie was set';
    }

    return isset($_COOKIE[PIXELFLOW_HELD_FORM_EVENTS_COOKIE_NAME]) ? true : 'the form hint cookie was not set';
}, $failures, $passes);

pf_forms_case('A missing credential keeps the queue instead of discarding it', function () {
    pf_hold_one();
    $GLOBALS['__pf_options']['pixelflow_script_params']['apiKey'] = '';
    pf_decide('granted');
    PixelFlow_Form_Dispatcher::resolve_held();

    return count(pf_held()) === 1 ? true : 'the queue was resolved without credentials';
}, $failures, $passes);

pf_forms_finish($failures, $passes);
